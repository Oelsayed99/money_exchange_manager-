<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\MysqlCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Restore the database from a backup.
 *
 * This is the most destructive command in the application: it replaces everything.
 * Two things stand in front of it.
 *
 * First, it takes a backup of what is about to be overwritten, before overwriting it,
 * and prints where that went. Restoring the wrong file is a mistake somebody makes at
 * two in the morning while already upset, and it should cost minutes rather than the
 * year's records.
 *
 * Second, it names the database it is about to replace and waits to be told to go on.
 * `--force` skips the asking, never the safety backup.
 */
final class DatabaseRestore extends Command
{
    protected $signature = 'db:restore
        {file : The .sql.gz backup to restore}
        {--force : Do not ask for confirmation}
        {--skip-safety-backup : Do not back up the current database first (not advised)}
        {--backup-path= : Where to put the safety backup, defaults to storage/backups}';

    protected $description = 'Replace the database with the contents of a backup';

    public function handle(): int
    {
        $connection = config('database.connections.'.config('database.default'));

        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'mysql') {
            $this->error('Only MySQL is supported. See ADR 0002.');

            return self::FAILURE;
        }

        $archive = (string) $this->argument('file');

        if (! File::isFile($archive)) {
            $this->error("No such backup: {$archive}");

            return self::FAILURE;
        }

        $database = (string) $connection['database'];

        $this->warn("This replaces everything in [{$database}] with the contents of {$archive}.");

        if (! $this->option('force') && ! $this->confirm("Replace [{$database}] now?", false)) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('skip-safety-backup') && ! $this->safetyBackup($database)) {
            return self::FAILURE;
        }

        try {
            $sql = $this->contents($archive, $database);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $credentials = new MysqlCredentials($connection);

        $process = new Process(['mysql', $credentials->argument()], timeout: 3600, input: $sql);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('Restore failed: '.trim($process->getErrorOutput()));
            $this->warn('The database may be half-replaced. Restore the safety backup printed above.');

            return self::FAILURE;
        }

        $this->info("Restored [{$database}] from {$archive}.");
        $this->line('Checking the ledger…');

        // A restore that leaves the books not balancing is a fact worth learning now
        // rather than from a customer.
        return Artisan::call('ledger:verify', ['--transactions' => true], $this->getOutput()) === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function safetyBackup(string $database): bool
    {
        $this->line("Backing up the current [{$database}] first…");

        $path = $this->option('backup-path');

        $code = Artisan::call('db:backup', [
            '--keep' => 50,
            ...(is_string($path) && $path !== '' ? ['--path' => $path] : []),
        ], $this->getOutput());

        if ($code !== self::SUCCESS) {
            $this->error('The safety backup failed, so nothing has been restored.');
            $this->line('Use --skip-safety-backup only if you are certain the current data is worthless.');

            return false;
        }

        return true;
    }

    /**
     * The SQL to replay, aimed at the configured database.
     *
     * Dumps carry `CREATE DATABASE` and `USE` for the database they came from, so a
     * backup of production replayed on a laptop would otherwise recreate production's
     * database and leave the local one untouched — appearing to succeed while changing
     * nothing anybody was looking at.
     */
    private function contents(string $archive, string $database): string
    {
        $raw = @gzdecode((string) File::get($archive));

        if ($raw === false) {
            throw new RuntimeException("{$archive} is not a gzip archive. Backups from this application end in .sql.gz.");
        }

        if (! str_contains($raw, 'Dump completed')) {
            throw new RuntimeException('That backup is incomplete — it has no end marker — and restoring it would leave a partial database.');
        }

        if (preg_match('/^CREATE DATABASE.*?`([^`]+)`/mi', $raw, $matches) !== 1) {
            throw new RuntimeException('That backup does not name a database, so there is nothing to aim at the current one.');
        }

        $origin = $matches[1];

        if ($origin !== $database) {
            $this->warn("The backup came from [{$origin}]; it will be restored into [{$database}].");
        }

        return str_replace('`'.$origin.'`', '`'.$database.'`', $raw);
    }
}
