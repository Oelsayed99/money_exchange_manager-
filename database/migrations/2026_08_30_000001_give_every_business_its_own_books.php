<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One set of books per business, sharing nothing.
 *
 * Until now the application was one business's books, run by a handful of named people
 * on one machine. Online, every sign-up is a different exchange office, and the only
 * acceptable failure mode is that one of them can never see another's balances, names,
 * rates or margins.
 *
 * So `business_id` goes on every table that holds anything, including the ones a join
 * would already reach. It is redundant on `ledger_balances` and `transaction_legs` — a
 * balance belongs to a ledger account, which belongs to a business — but four of the
 * read models query those tables directly with the query builder rather than through
 * Eloquent, and a global scope cannot reach a raw query. A column those queries can
 * filter on is worth more than the normalisation it costs.
 *
 * ## Currencies are per business too
 *
 * A currency here is not a constant. It carries an active flag and a sort order, which
 * are one business's preferences about how their own screens read. Shared, one office
 * deactivating a currency would remove it from everyone's forms.
 *
 * ## What happens to what is already here
 *
 * Everything that exists becomes one founding business. Nothing is deleted and nothing
 * is guessed at — a row that exists today plainly belongs to the only business there
 * has ever been. The ledger has to be empty first for the same reason as ADR 0032: the
 * owner is re-seeding, and a migration that quietly reassigns posted money is a
 * migration nobody can check.
 */
return new class extends Migration
{
    /**
     * Tables that hold something belonging to one business.
     *
     * Ordered so a reader can see the shape: reference data, then parties, then the
     * ledger, then the record of who did what.
     *
     * @var list<string>
     */
    private const array SCOPED = [
        'currencies',
        'accounts',
        'counterparties',
        'counterparty_opening_balances',
        'ledger_accounts',
        'ledger_balances',
        'ledger_entries',
        'transactions',
        'transaction_legs',
        'reconciliations',
        'audit_logs',
    ];

    /**
     * Unique keys that were global and have to become per business.
     *
     * Two offices will both call a currency EGP, and both will hold a `cash:system`
     * ledger account. Left global, the second business to sign up could not save.
     *
     * @var array<string, array{string, list<string>}>
     */
    private const array UNIQUES = [
        'currencies' => ['currencies_code_unique', ['code']],
        'ledger_accounts' => ['ledger_accounts_code_unique', ['code']],
        'transactions' => ['transactions_idempotency_key_unique', ['idempotency_key']],
    ];

    public function up(): void
    {
        $this->refuseIfTheLedgerHasHistory();

        Schema::create('businesses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');

            // The person who signed up. Nullable only so the founding business below
            // can be created before anybody is attached to it.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('locale', 5)->default('en');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('business_id')->nullable()->after('id')
                ->constrained('businesses')->cascadeOnDelete();
        });

        foreach (self::SCOPED as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                // Nullable for now; filled below and tightened at the end, because a
                // NOT NULL column cannot be added to a table that already has rows.
                $blueprint->foreignId('business_id')->nullable()->after('id')
                    ->constrained('businesses')->cascadeOnDelete();

                $blueprint->index(['business_id', 'id'], $table.'_business_index');
            });
        }

        $this->attachWhatIsAlreadyHere();

        foreach (self::SCOPED as $table) {
            // The audit trail keeps its null: a business being created is a platform
            // event with no business to attribute it to yet. Everything else must
            // belong to somebody.
            if ($table === 'audit_logs') {
                continue;
            }

            DB::statement("ALTER TABLE {$table} MODIFY business_id BIGINT UNSIGNED NOT NULL");
        }

        foreach (self::UNIQUES as $table => [$name, $columns]) {
            Schema::table($table, function (Blueprint $blueprint) use ($name, $columns): void {
                $blueprint->unique(['business_id', ...$columns], $name.'_per_business');
            });

            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropUnique($name);
            });
        }
    }

    public function down(): void
    {
        $this->refuseIfTheLedgerHasHistory();

        foreach (self::UNIQUES as $table => [$name, $columns]) {
            Schema::table($table, function (Blueprint $blueprint) use ($name, $columns): void {
                $blueprint->unique($columns, $name);
            });

            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropUnique($name.'_per_business');
            });
        }

        foreach (array_reverse(self::SCOPED) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropForeign([$table.'_business_id_foreign']);
                $blueprint->dropIndex($table.'_business_index');
                $blueprint->dropColumn('business_id');
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['business_id']);
            $table->dropColumn('business_id');
        });

        Schema::dropIfExists('businesses');
    }

    /**
     * Everything that exists becomes the books of one founding business.
     *
     * Not a guess: there has only ever been one business in this database, so every row
     * in it is that business's. Skipped entirely on a fresh install, where there is
     * nothing to attach and the first sign-up creates the first business.
     */
    private function attachWhatIsAlreadyHere(): void
    {
        $hasSomething = collect(self::SCOPED)
            ->contains(fn (string $table): bool => DB::table($table)->exists());

        if (! $hasSomething && ! DB::table('users')->exists()) {
            return;
        }

        $id = DB::table('businesses')->insertGetId([
            'name' => 'MonyMonk',
            'locale' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([...self::SCOPED, 'users'] as $table) {
            DB::table($table)->update(['business_id' => $id]);
        }

        $owner = DB::table('users')->orderBy('id')->value('id');

        if ($owner !== null) {
            DB::table('businesses')->where('id', $id)->update(['owner_id' => $owner]);
        }
    }

    private function refuseIfTheLedgerHasHistory(): void
    {
        $entries = DB::table('ledger_entries')->count();

        if ($entries > 0) {
            throw new RuntimeException(
                "There are {$entries} ledger entries here from before books were kept per "
                .'business. They would all be handed to one founding business, which is right '
                .'when that is what they are and wrong the moment it is not. Run '
                .'`php artisan ledger:purge` first, or restore a backup and export what you need.'
            );
        }
    }
};
