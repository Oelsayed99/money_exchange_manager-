<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Enums\EntryDirection;
use App\Enums\LegRole;
use App\Enums\MovementMethod;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transactions, their legs, the ledger entries they produce, and the balance cache.
 *
 * See docs/posting-rules.md. The entries table is append-only and enforced as such by
 * the database: Section 7 requires that completed transactions are never hard deleted
 * and that every balance change is traceable to a transaction, and a ledger the
 * application can quietly rewrite provides neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();

            $table->string('type', 40);
            $table->string('status', 20)->default(TransactionStatus::Draft->value);

            // When the money actually moved, which is not when the row was created.
            // The sample statement had rows entered out of date order; balances are
            // sums, so order of entry does not matter, but reporting needs the truth.
            $table->timestamp('occurred_at');

            $table->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 20)->nullable();

            $table->string('reference')->nullable();
            $table->text('description')->nullable();

            // Section 7: protect against duplicate submission. A double-clicked
            // exchange must post once, not twice.
            $table->string('idempotency_key', 100)->nullable()->unique();

            $table->foreignId('reversal_of_transaction_id')->nullable()
                ->constrained('transactions')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
            $table->index('occurred_at');
        });

        Schema::create('transaction_legs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();

            $table->string('role', 20);
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 28, Money::SCALE);

            // Where it came from and where it went. Either side may be one of our
            // custody locations, a counterparty, or neither.
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedTinyInteger('sequence')->default(0);

            $table->timestamps();

            $table->index('transaction_id');
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();

            // Denormalised from the ledger account. Every balance and report query
            // filters by currency, and joining to find it would defeat the indexes.
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();

            $table->string('direction', 10);
            $table->decimal('amount', 28, Money::SCALE);

            // Position within the transaction, so entries read back in the order they
            // were written rather than in whatever order the database returns them.
            $table->unsignedTinyInteger('sequence')->default(0);

            $table->timestamp('occurred_at');

            // created_at only. An entry is never updated; see the triggers below.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ledger_account_id', 'occurred_at']);
            $table->index(['transaction_id', 'sequence']);
            $table->index(['currency_id', 'occurred_at']);
        });

        Schema::create('ledger_balances', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('ledger_account_id')->unique()->constrained()->cascadeOnDelete();

            // Signed, in the account's own direction: positive means the account holds
            // what its kind implies it should. One sum, not two columns subtracted.
            $table->decimal('confirmed_amount', 28, Money::SCALE)->default(0);

            // Pending movements that would reduce the account. Available balance is
            // confirmed minus this. Pending *inflows* are deliberately excluded:
            // money somebody has promised is not money that can be spent.
            $table->decimal('pending_decrease_amount', 28, Money::SCALE)->default(0);

            $table->unsignedBigInteger('last_entry_id')->nullable();

            $table->timestamps();
        });

        foreach ([
            ['transactions', 'type', TransactionType::values()],
            ['transactions', 'status', TransactionStatus::values()],
            ['transaction_legs', 'role', LegRole::values()],
            ['ledger_entries', 'direction', EntryDirection::values()],
        ] as [$table, $column, $allowed]) {
            DB::statement(sprintf(
                "ALTER TABLE %s ADD CONSTRAINT chk_%s_%s CHECK (%s IN ('%s'))",
                $table, $table, $column, $column, implode("','", $allowed),
            ));
        }

        DB::statement(sprintf(
            "ALTER TABLE transactions ADD CONSTRAINT chk_transactions_method CHECK (method IS NULL OR method IN ('%s'))",
            implode("','", MovementMethod::values()),
        ));

        // An amount of zero or less is not a movement. Direction carries the sign; a
        // negative entry would mean the same thing twice and break every sum.
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT chk_ledger_entries_amount CHECK (amount > 0)');
        DB::statement('ALTER TABLE transaction_legs ADD CONSTRAINT chk_transaction_legs_amount CHECK (amount > 0)');

        // Append-only, enforced by the database. Application guards can be changed or
        // bypassed by accident; a trigger can only be removed by a visible schema
        // change. A correction is a reversal, never an edit.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_entries_no_update
            BEFORE UPDATE ON ledger_entries
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ledger_entries is append-only: correct a mistake with a reversal, never an edit.';
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_entries_no_delete
            BEFORE DELETE ON ledger_entries
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ledger_entries is append-only: entries cannot be deleted.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_delete');

        Schema::dropIfExists('ledger_balances');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('transaction_legs');
        Schema::dropIfExists('transactions');
    }
};
