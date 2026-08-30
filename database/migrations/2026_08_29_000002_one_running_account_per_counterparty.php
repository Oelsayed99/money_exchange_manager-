<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Four positions per counterparty become one, and nine movement types become two.
 *
 * Custody, receivable, payable and credit held were kept apart so that "they owe me"
 * and "I am holding their money" could never be confused. In daily use they turned out
 * to be four descriptions of one relationship, and the classification had to be made at
 * the counter, before the operator necessarily knew which one applied. See ADR 0032.
 *
 * What replaces them is one signed running balance per party per currency: **positive
 * means they owe us**, negative means we are holding theirs.
 *
 * Likewise money received, money paid, loans either way, both settlements, both credit
 * movements and a refund were nine names for two events — money came in from somebody,
 * or it went out to them.
 *
 * ## This migration refuses to guess
 *
 * Folding an existing four-bucket history into one balance means deciding what each old
 * movement *meant*, and only the person who recorded it knows. So it stops rather than
 * choosing. Clear the ledger first — `php artisan ledger:purge` — which is what the
 * owner chose to do, the application not yet being in real use.
 */
return new class extends Migration
{
    private const SUBKINDS = [
        'cash', 'client_account', 'fx_position', 'trading_profit', 'fees_income',
        'expense', 'commission_expense', 'opening_equity', 'capital', 'adjustment_equity',
    ];

    private const SUBKINDS_BEFORE = [
        'cash', 'custody', 'receivable', 'payable', 'credit_trust', 'fx_position',
        'trading_profit', 'fees_income', 'expense', 'commission_expense',
        'opening_equity', 'capital', 'adjustment_equity',
    ];

    private const TYPES = [
        'opening_balance', 'deposit', 'withdrawal', 'transfer', 'in', 'out',
        'currency_exchange', 'fee', 'expense', 'profit_adjustment',
        'balance_adjustment', 'refund', 'reversal',
    ];

    /** The nineteen that existed before. A rollback has to restore exactly these. */
    private const TYPES_BEFORE = [
        'opening_balance', 'deposit', 'withdrawal', 'transfer', 'money_received',
        'money_paid', 'loan_given', 'loan_received', 'receivable_settlement',
        'payable_settlement', 'currency_exchange', 'credit_deposit', 'credit_settlement',
        'fee', 'expense', 'profit_adjustment', 'balance_adjustment', 'refund', 'reversal',
    ];

    public function up(): void
    {
        $this->refuseIfAnyHistoryExists();
        $this->retireTheFourPositions();

        $this->replaceCheck('ledger_accounts', 'chk_ledger_accounts_subkind', 'subkind', self::SUBKINDS);
        $this->replaceCheck('transactions', 'chk_transactions_type', 'type', self::TYPES);

        // The bucket was half of what made a position unique. Without it, a party has
        // one row per currency — which is the whole point.
        $this->dropCheckIfPresent('counterparty_opening_balances', 'chk_counterparty_bucket');

        // The new index goes on before the old one comes off. MySQL refuses to drop an
        // index a foreign key is leaning on, and `counterparty_id` leads both.
        Schema::table('counterparty_opening_balances', function (Blueprint $table): void {
            $table->unique(['counterparty_id', 'currency_id'], 'counterparty_opening_per_currency');
        });

        Schema::table('counterparty_opening_balances', function (Blueprint $table): void {
            $table->dropUnique('counterparty_opening_unique');
            $table->dropColumn('bucket');
        });
    }

    public function down(): void
    {
        $this->refuseIfAnyHistoryExists();

        Schema::table('counterparty_opening_balances', function (Blueprint $table): void {
            $table->string('bucket', 32)->after('currency_id');
            $table->unique(['counterparty_id', 'bucket', 'currency_id'], 'counterparty_opening_unique');
        });

        Schema::table('counterparty_opening_balances', function (Blueprint $table): void {
            $table->dropUnique('counterparty_opening_per_currency');
        });

        DB::statement(
            'ALTER TABLE counterparty_opening_balances ADD CONSTRAINT chk_counterparty_bucket '
            ."CHECK (bucket IN ('custody','receivable','payable','credit_trust'))"
        );

        $this->replaceCheck('transactions', 'chk_transactions_type', 'type', self::TYPES_BEFORE);
        $this->replaceCheck('ledger_accounts', 'chk_ledger_accounts_subkind', 'subkind', self::SUBKINDS_BEFORE);
    }

    private function refuseIfAnyHistoryExists(): void
    {
        $entries = DB::table('ledger_entries')->count();

        if ($entries > 0) {
            throw new RuntimeException(
                "There are {$entries} ledger entries recorded under the four-position model. Folding "
                .'them into one balance means deciding what each old movement meant, and only whoever '
                .'recorded it knows. Run `php artisan ledger:purge` first, or restore a backup taken '
                .'before this change and export what you need.'
            );
        }
    }

    /** @param  list<string>  $allowed */
    private function replaceCheck(string $table, string $constraint, string $column, array $allowed): void
    {
        $this->dropCheckIfPresent($table, $constraint);

        DB::statement(sprintf(
            "ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s IN ('%s'))",
            $table,
            $constraint,
            $column,
            implode("','", $allowed),
        ));
    }

    /**
     * Accounts in the four subkinds this migration retires.
     *
     * The refusal above has already established that no entry was ever posted to them.
     * What is left is what the account resolver created on demand and nothing wrote to;
     * the new CHECK would reject those rows where they sit, and nothing reads them.
     */
    private function retireTheFourPositions(): void
    {
        $retired = ['custody', 'receivable', 'payable', 'credit_trust'];

        $ids = DB::table('ledger_accounts')->whereIn('subkind', $retired)->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        DB::table('ledger_balances')->whereIn('ledger_account_id', $ids)->delete();
        DB::table('ledger_accounts')->whereIn('id', $ids)->delete();
    }

    /**
     * MySQL has no `DROP CHECK IF EXISTS`.
     *
     * A database carried forward through development can be missing a constraint that a
     * fresh install has — and then this migration, which is the one that would put it
     * back, is the thing that fails. Dropping what is there and adding what should be
     * leaves both databases in the same state.
     */
    private function dropCheckIfPresent(string $table, string $constraint): void
    {
        $present = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->exists();

        if ($present) {
            DB::statement("ALTER TABLE {$table} DROP CHECK {$constraint}");
        }
    }
};
