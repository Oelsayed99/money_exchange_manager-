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
 * Everything that exists becomes one founding business, ledger and all. That is not a
 * guess and needs no permission: before this migration there is no such thing as a
 * second business, so every row in the database provably belongs to the only one there
 * has ever been.
 *
 * This first refused to run while `ledger_entries` had anything in it, by analogy with
 * the migration in ADR 0032 — which was right *there*, because folding four positions
 * into one meant deciding what each old movement had meant. Here there is nothing to
 * decide. All the guard achieved was to make an existing office purge its books to come
 * along, which is a real loss in exchange for nothing.
 *
 * Going back is the direction that can lose something, so that is where the guard is
 * now: `down()` refuses once a second business exists, because dropping the column then
 * would merge two offices' ledgers into one undifferentiated pile.
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
        // Every step below asks whether it has already been done.
        //
        // MySQL does not roll DDL back. A migration that fails partway through leaves
        // half its columns in place and no record that it ran, and the only way out is
        // to re-run it — which then dies on the first table it already created. Written
        // this way, re-running finishes the job.
        if (! Schema::hasTable('businesses')) {
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
        }

        if (! Schema::hasColumn('users', 'business_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreignId('business_id')->nullable()->after('id')
                    ->constrained('businesses')->cascadeOnDelete();
            });
        }

        foreach (self::SCOPED as $table) {
            if (Schema::hasColumn($table, 'business_id')) {
                continue;
            }

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
            if (! $this->indexExists($table, $name.'_per_business')) {
                Schema::table($table, function (Blueprint $blueprint) use ($name, $columns): void {
                    $blueprint->unique(['business_id', ...$columns], $name.'_per_business');
                });
            }

            if ($this->indexExists($table, $name)) {
                Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                    $blueprint->dropUnique($name);
                });
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }

    /**
     * Run something with the append-only triggers temporarily removed.
     *
     * Read back from the database rather than written out here, so this cannot drift
     * from whatever the triggers actually are. Restored in a `finally`, so there is no
     * path — including an exception mid-backfill — that leaves the ledger editable.
     */
    private function withAppendOnlyGuardsOff(Closure $work): void
    {
        $triggers = [];

        foreach (DB::select('SHOW TRIGGERS') as $row) {
            $name = (string) $row->Trigger;
            $definition = DB::select("SHOW CREATE TRIGGER `{$name}`")[0];

            $triggers[$name] = (string) $definition->{'SQL Original Statement'};
        }

        foreach (array_keys($triggers) as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
        }

        try {
            $work();
        } finally {
            foreach ($triggers as $name => $sql) {
                DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
                DB::unprepared($sql);
            }
        }
    }

    public function down(): void
    {
        $this->refuseIfMoreThanOneBusinessExists();

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

        // A previous failed run may have got this far already.
        $id = DB::table('businesses')->orderBy('id')->value('id') ?? DB::table('businesses')->insertGetId([
            'name' => 'MonyMonk',
            'locale' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // `ledger_entries` and `audit_logs` carry BEFORE UPDATE triggers that refuse
        // every update, which is the point of them — and which also refuses this one.
        //
        // Stamping which books a row was always in is not editing what happened, and
        // the alternative would be to make an existing office erase its ledger to come
        // along. So the guards come off for the length of the backfill and go straight
        // back on, the same way `ledger:purge` does it, and for the same reason: this
        // is a named, visible operation rather than somebody at a prompt.
        $this->withAppendOnlyGuardsOff(function () use ($id): void {
            foreach ([...self::SCOPED, 'users'] as $table) {
                DB::table($table)->whereNull('business_id')->update(['business_id' => $id]);
            }
        });

        $owner = DB::table('users')->orderBy('id')->value('id');

        if ($owner !== null) {
            DB::table('businesses')->where('id', $id)->update(['owner_id' => $owner]);
        }
    }

    /**
     * The direction that can destroy something.
     *
     * Rolling forward merges nothing: there is only ever one business to attach rows to.
     * Rolling back with several would drop the one column that says whose each row is,
     * leaving every office's clients, balances and entries in a single pile with no way
     * to tell them apart again.
     */
    private function refuseIfMoreThanOneBusinessExists(): void
    {
        $businesses = DB::table('businesses')->count();

        if ($businesses > 1) {
            throw new RuntimeException(
                "There are {$businesses} businesses here. Rolling this back drops the column "
                .'that says which of them each client, balance and ledger entry belongs to, '
                .'and they cannot be told apart again afterwards. Export what you need first, '
                .'or delete the businesses you are not keeping.'
            );
        }
    }
};
