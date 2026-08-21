<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\MysqlCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Take a backup of the database, and prove it is worth having.
 *
 * A backup nobody has restored is a hope, not a backup. `--verify` restores this one
 * into a scratch database and runs `ledger:verify` against it, so what is asserted is
 * not "the file exists" but "this file can be turned back into a ledger that balances".
 * Files that fail are kept, loudly, rather than deleted — a broken backup is evidence.
 */
final class DatabaseBackup extends Command
{
    protected $signature = 'db:backup
        {--keep=14 : How many backups to keep; older ones are deleted}
        {--verify : Restore into a scratch database and check the ledger balances}
        {--path= : Where to write, defaults to storage/backups}';

    protected $description = 'Back up the database, optionally proving the backup restores';

    public function handle(): int
    {
        $connection = config('database.connections.'.config('database.default'));

        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'mysql') {
            $this->error('Only MySQL is supported. See ADR 0002.');

            return self::FAILURE;
        }

        $directory = $this->directory();
        File::ensureDirectoryExists($directory, 0700);

        $database = (string) $connection['database'];
        $target = $this->uniquePath($directory, $database);

        $this->line("Backing up <info>{$database}</info>");

        $dump = $this->dump($connection, $database);

        if ($dump === null) {
            return self::FAILURE;
        }

        $this->compress($dump, $target);
        File::delete($dump);

        $size = File::size($target);
        $this->info('Wrote '.$target.' ('.$this->humanSize($size).')');

        if ($this->option('verify') && ! $this->verify($connection, $target)) {
            return self::FAILURE;
        }

        $this->prune($directory, $database);

        return self::SUCCESS;
    }

    /**
     * Run mysqldump, returning the path to the raw dump or null if it failed.
     *
     * Single-transaction so the dump is a consistent snapshot without locking the
     * tables — a backup that blocks the counter for a minute is a backup somebody
     * eventually stops taking. Routines and triggers are included because this schema
     * depends on them: the append-only enforcement on ledger entries, audit logs and
     * reconciliations lives in triggers, and a restore without them would come back
     * silently editable.
     *
     * @param  array<string, mixed>  $connection
     */
    private function dump(array $connection, string $database): ?string
    {
        $credentials = new MysqlCredentials($connection);
        $raw = $this->directory().'/.'.$database.'-'.now()->format('Y-m-d-His').'.sql';

        $process = new Process([
            'mysqldump',
            $credentials->argument(),
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            '--default-character-set=utf8mb4',
            // Without this mysqldump writes SET @@GLOBAL.GTID_PURGED, and replaying the
            // file on the server it came from fails outright: "the added gtid set must
            // not overlap with @@GLOBAL.GTID_EXECUTED". Found by --verify on the very
            // first run, which is the entire argument for verifying.
            '--set-gtid-purged=OFF',
            // Named in the file so a restore cannot land in the wrong database by
            // accident, and readable by a person opening it in an editor.
            '--databases',
            $database,
            '--result-file='.$raw,
        ], timeout: 1800);

        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('mysqldump failed: '.trim($process->getErrorOutput()));
            File::delete($raw);

            return null;
        }

        // mysqldump writes this line last. Its absence means the dump was truncated —
        // a disk filling up mid-write produces a file that looks fine until the day it
        // is needed.
        if (! str_contains((string) file_get_contents($raw), 'Dump completed')) {
            $this->error('The dump is incomplete: mysqldump did not finish writing it.');
            File::delete($raw);

            return null;
        }

        return $raw;
    }

    /** Compress in chunks, so the size of the database is not the size of the memory needed. */
    private function compress(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb9');

        if ($in === false || $out === false) {
            throw new \RuntimeException("Could not compress {$source}.");
        }

        while (! feof($in)) {
            $chunk = fread($in, 1024 * 512);

            if ($chunk === false) {
                break;
            }

            gzwrite($out, $chunk);
        }

        fclose($in);
        gzclose($out);

        chmod($target, 0600);
    }

    /**
     * Restore into a scratch database and check the ledger balances there.
     *
     * The scratch database is dropped afterwards whatever happens. It is never the
     * live one: the name is derived and asserted different before anything runs.
     *
     * @param  array<string, mixed>  $connection
     */
    private function verify(array $connection, string $archive): bool
    {
        $live = (string) $connection['database'];
        $scratch = $live.'_verify';

        if ($scratch === $live) {
            $this->error('Refusing to verify: the scratch database resolved to the live one.');

            return false;
        }

        $this->line("Verifying into <info>{$scratch}</info>");

        $credentials = new MysqlCredentials($connection);

        try {
            $this->mysql($credentials, "DROP DATABASE IF EXISTS `{$scratch}`; CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;");

            // The dump names its own database, so it is replayed with USE overridden to
            // land in the scratch one instead.
            $sql = (string) gzdecode((string) file_get_contents($archive));
            $sql = str_replace(["`{$live}`"], ["`{$scratch}`"], $sql);

            $this->mysql($credentials, $sql);

            $verified = $this->ledgerVerifies($connection, $scratch);
        } catch (\Throwable $exception) {
            $this->error('Verification failed: '.$exception->getMessage());
            $verified = false;
        } finally {
            $this->mysql($credentials, "DROP DATABASE IF EXISTS `{$scratch}`;", throw: false);
        }

        if ($verified) {
            $this->info('Verified: the backup restores and the ledger balances.');

            return true;
        }

        // Kept deliberately. A backup that does not restore is the most important file
        // in the directory.
        $this->error('This backup did not verify. It has been kept at '.$archive.' for inspection.');

        return false;
    }

    /** @param array<string, mixed> $connection */
    private function ledgerVerifies(array $connection, string $scratch): bool
    {
        config(['database.connections.backup_verify' => [...$connection, 'database' => $scratch]]);
        DB::purge('backup_verify');

        $previous = config('database.default');
        config(['database.default' => 'backup_verify']);

        try {
            return Artisan::call('ledger:verify', ['--transactions' => true]) === 0;
        } finally {
            config(['database.default' => $previous]);
            DB::purge('backup_verify');
        }
    }

    private function mysql(MysqlCredentials $credentials, string $sql, bool $throw = true): void
    {
        $process = new Process(['mysql', $credentials->argument()], timeout: 1800, input: $sql);
        $process->run();

        if ($throw && ! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()));
        }
    }

    /** Delete all but the newest few, so a backup directory cannot fill a disk unattended. */
    private function prune(string $directory, string $database): void
    {
        $keep = max(1, (int) $this->option('keep'));

        $backups = collect(File::glob($directory.'/'.$database.'-*.sql.gz'))
            ->sortDesc()
            ->values();

        $stale = $backups->slice($keep);

        foreach ($stale as $path) {
            File::delete($path);
        }

        if ($stale->isNotEmpty()) {
            $this->line('Removed '.$stale->count().' backup(s) older than the newest '.$keep.'.');
        }
    }

    /**
     * A path nothing is already using.
     *
     * The name is built from the second, and two backups can happen inside one — a
     * restore takes a safety copy immediately after somebody has taken a manual
     * backup, and both would land on the same filename with the second silently
     * replacing the first. Losing a backup to a name collision is a quiet way to lose
     * the one that mattered.
     */
    private function uniquePath(string $directory, string $database): string
    {
        $base = $directory.'/'.$database.'-'.now()->format('Y-m-d-His');
        $path = $base.'.sql.gz';

        for ($suffix = 2; File::exists($path); $suffix++) {
            $path = $base.'-'.$suffix.'.sql.gz';
        }

        return $path;
    }

    private function directory(): string
    {
        $path = $this->option('path');

        return is_string($path) && $path !== '' ? rtrim($path, '/') : storage_path('backups');
    }

    private function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return $bytes.' '.$unit;
            }

            $bytes = intdiv($bytes, 1024);
        }

        return $bytes.' TB';
    }
}
