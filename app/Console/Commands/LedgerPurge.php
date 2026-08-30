<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Business;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Erase every recorded movement, keeping everything the business is set up with.
 *
 * For the one moment this is honestly needed: clearing the trial entries somebody typed
 * while learning the application, before the ledger starts meaning something. After
 * that, a mistake is corrected with a reversal — which is what the append-only triggers
 * on `ledger_entries` and `audit_logs` are there to insist on.
 *
 * This command drops those triggers, deletes, and puts them back. That is not a licence
 * to edit history; it is the reason this is a named command that says so out loud and
 * takes a backup first, rather than a sequence of statements somebody assembles at a
 * prompt with the triggers off and no record of what they did.
 *
 * ## What survives
 *
 * Currencies, accounts, counterparties and their **declared opening balances**, users,
 * roles and permissions, and the ledger's own chart of accounts. Everything that
 * describes the business rather than what it did.
 *
 * `--openings` additionally clears the declared opening balances, which are figures
 * somebody typed about a client and may be as real as anything in the ledger. Off by
 * default for that reason.
 */
final class LedgerPurge extends Command
{
    private Business $business;

    protected $signature = 'ledger:purge
        {--business= : Whose movements to delete, by id or by name. Required once there is more than one}
        {--force : Do not ask for confirmation}
        {--openings : Also clear counterparty opening balances}
        {--skip-backup : Do not back the database up first (not advised)}';

    protected $description = 'Delete every recorded transaction, keeping currencies, accounts, counterparties and users';

    /**
     * In this order. Children before parents, so no foreign key is left pointing at
     * nothing at any point during the delete.
     *
     * @var list<string>
     */
    private const TABLES = [
        'ledger_entries',
        'ledger_balances',
        'transaction_legs',
        'reconciliations',
        'transactions',
        'audit_logs',
    ];

    /**
     * The append-only guards, and how to put each one back.
     *
     * Written out here rather than read from the migrations: this has to restore
     * exactly what was there, and a command that reconstructs a trigger by guessing is
     * worse than one that cannot restore it at all.
     *
     * @var array<string, string>
     */
    private const TRIGGERS = [
        'ledger_entries_no_update' => <<<'SQL'
            CREATE TRIGGER ledger_entries_no_update
            BEFORE UPDATE ON ledger_entries
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ledger_entries is append-only: correct a mistake with a reversal, never an edit.';
        SQL,
        'ledger_entries_no_delete' => <<<'SQL'
            CREATE TRIGGER ledger_entries_no_delete
            BEFORE DELETE ON ledger_entries
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ledger_entries is append-only: entries cannot be deleted.';
        SQL,
        'audit_logs_no_update' => <<<'SQL'
            CREATE TRIGGER audit_logs_no_update
            BEFORE UPDATE ON audit_logs
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'audit_logs is append-only: rows cannot be updated.';
        SQL,
        'audit_logs_no_delete' => <<<'SQL'
            CREATE TRIGGER audit_logs_no_delete
            BEFORE DELETE ON audit_logs
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'audit_logs is append-only: rows cannot be deleted.';
        SQL,
    ];

    public function handle(): int
    {
        $business = $this->whoseBooks();

        if (! $business instanceof Business) {
            return self::FAILURE;
        }

        $this->business = $business;

        $database = (string) DB::connection()->getDatabaseName();
        $counts = $this->counts();

        $this->warn(
            "This deletes every recorded movement belonging to [{$business->name}] in "
            ."[{$database}]. It cannot be undone."
        );
        $this->newLine();
        $this->table(['Table', 'Rows'], array_map(
            fn (string $table): array => [$table, $counts[$table]],
            array_keys($counts),
        ));
        $this->line('Currencies, accounts, counterparties, users and roles are kept.');

        if ($this->option('openings')) {
            $this->warn('--openings: declared opening balances will be cleared too.');
        }

        if (! $this->option('force') && ! $this->confirm("Delete everything above from [{$database}]?", false)) {
            $this->info('Nothing was deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('skip-backup') && Artisan::call('db:backup', [], $this->getOutput()) !== self::SUCCESS) {
            $this->error('The backup failed, so nothing was deleted.');

            return self::FAILURE;
        }

        $this->purge();

        $this->newLine();
        $this->info('Deleted. Verifying the ledger is empty and consistent.');

        return Artisan::call('ledger:verify', ['--transactions' => true], $this->getOutput());
    }

    private function purge(): void
    {
        // The triggers are off for the length of this and nothing else. A failure
        // anywhere in between rolls the deletes back and the finally puts them back, so
        // there is no path that leaves the ledger editable.
        $this->dropTriggers();

        try {
            DB::transaction(function (): void {
                foreach (self::TABLES as $table) {
                    $this->rowsOf($table)->delete();
                }

                if ($this->option('openings')) {
                    $this->rowsOf('counterparty_opening_balances')->delete();
                }
            });
        } finally {
            $this->restoreTriggers();
        }
    }

    private function dropTriggers(): void
    {
        foreach (array_keys(self::TRIGGERS) as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    private function restoreTriggers(): void
    {
        foreach (self::TRIGGERS as $trigger => $sql) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            DB::unprepared($sql);
        }
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            $counts[$table] = $this->rowsOf($table)->count();
        }

        if ($this->option('openings')) {
            $counts['counterparty_opening_balances'] = $this->rowsOf('counterparty_opening_balances')->count();
        }

        return $counts;
    }

    /**
     * One table, narrowed to the business being purged.
     *
     * The reason this command takes a business at all. Before books were kept per
     * business there was only ever one set to erase; now an unqualified delete here
     * would empty every customer's ledger at once, and the triggers are off while it
     * runs.
     */
    private function rowsOf(string $table): Builder
    {
        return DB::table($table)->where('business_id', $this->business->getKey());
    }

    /**
     * Which business to purge.
     *
     * With one business, that one, and the command behaves exactly as it did before
     * any of this existed. With several, saying so is required: there is no sensible
     * default for which customer's ledger to erase.
     */
    private function whoseBooks(): ?Business
    {
        $named = $this->option('business');

        if (is_string($named) && $named !== '') {
            $business = Business::query()
                ->where(fn ($q) => $q->where('id', $named)->orWhere('name', $named))
                ->first();

            if (! $business instanceof Business) {
                $this->error("No business matches [{$named}].");
            }

            return $business;
        }

        $all = Business::query()->orderBy('id')->get();

        if ($all->count() === 1) {
            return $all->first();
        }

        if ($all->isEmpty()) {
            $this->error('There are no businesses, so there is nothing to purge.');

            return null;
        }

        $this->error(
            'There is more than one business here. Say which one with --business=, by id or '
            .'by name. There is no default: this deletes a customer\'s ledger.'
        );

        $this->newLine();
        $this->table(['Id', 'Name'], $all->map(fn (Business $b): array => [$b->getKey(), $b->name])->all());

        return null;
    }
}
