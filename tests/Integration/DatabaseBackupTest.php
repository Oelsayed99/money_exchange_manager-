<?php

declare(strict_types=1);

use App\Models\Counterparty;
use App\Support\Database\MysqlCredentials;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Backup and restore, against a real database.
 *
 * These commit their data on purpose: mysqldump runs on its own connection and cannot
 * see anything sitting inside a rolled-back test transaction, so a backup taken under
 * RefreshDatabase would faithfully archive an empty database and pass.
 */
beforeEach(function (): void {
    $this->directory = storage_path('framework/testing/backups');

    File::deleteDirectory($this->directory);
    File::ensureDirectoryExists($this->directory);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

function backup(array $options = []): int
{
    return Artisan::call('db:backup', ['--path' => test()->directory, ...$options]);
}

function archives(): array
{
    return File::glob(test()->directory.'/*.sql.gz');
}

function sqlOf(string $archive): string
{
    return (string) gzdecode((string) File::get($archive));
}

describe('taking a backup', function (): void {
    it('writes a compressed dump', function (): void {
        expect(backup())->toBe(0)
            ->and(archives())->toHaveCount(1);

        expect(sqlOf(archives()[0]))->toStartWith('-- MySQL dump');
    });

    it('contains the data, not just the schema', function (): void {
        Counterparty::factory()->create(['name' => 'سالم التجريبي']);

        backup();

        expect(sqlOf(archives()[0]))->toContain('سالم التجريبي');
    });

    // The append-only enforcement on ledger entries, audit logs and reconciliations
    // lives in triggers. A restore without them comes back silently editable, which is
    // the kind of loss nobody notices until somebody edits something.
    it('includes the triggers that make the ledger append-only', function (): void {
        backup();

        expect(sqlOf(archives()[0]))->toContain('TRIGGER')
            ->and(sqlOf(archives()[0]))->toContain('ledger_entries');
    });

    it('ends with the marker that says it finished', function (): void {
        backup();

        expect(sqlOf(archives()[0]))->toContain('Dump completed');
    });

    // Replaying a dump carrying GTID_PURGED on the server it came from fails outright.
    // Found by --verify on the first run this command ever did.
    it('writes nothing that would refuse to replay', function (): void {
        backup();

        expect(sqlOf(archives()[0]))->not->toContain('GTID_PURGED');
    });

    it('keeps the file readable only by its owner', function (): void {
        backup();

        expect(substr(sprintf('%o', fileperms(archives()[0])), -3))->toBe('600');
    });
});

describe('retention', function (): void {
    it('keeps only the newest few', function (): void {
        foreach (range(1, 3) as $n) {
            File::put(test()->directory.'/finance_test-2020-01-0'.$n.'-000000.sql.gz', 'old');
        }

        backup(['--keep' => 2]);

        expect(archives())->toHaveCount(2);
    });

    // Two backups inside one second used to land on the same filename, the second
    // silently replacing the first — including when a restore's safety copy followed a
    // manual backup.
    it('does not overwrite a backup taken in the same second', function (): void {
        backup();
        backup();

        expect(archives())->toHaveCount(2);
    });

    it('never deletes everything, whatever it is asked', function (): void {
        backup(['--keep' => 0]);

        expect(archives())->not->toBeEmpty();
    });
});

describe('verifying a backup', function (): void {
    // The property worth having: not "a file exists" but "this file becomes a ledger
    // that balances".
    it('restores into a scratch database and checks the books', function (): void {
        Counterparty::factory()->create();

        expect(backup(['--verify' => true]))->toBe(0);
    });

    it('leaves no scratch database behind', function (): void {
        backup(['--verify' => true]);

        $scratch = config('database.connections.mysql.database').'_verify';

        // information_schema rather than SHOW DATABASES: the latter takes no bindings.
        expect(DB::table('information_schema.schemata')->where('schema_name', $scratch)->exists())->toBeFalse();
    });
});

describe('restoring', function (): void {
    it('brings back data deleted after the backup', function (): void {
        $party = Counterparty::factory()->create(['name' => 'Comes back']);
        backup();

        $party->forceDelete();
        expect(Counterparty::query()->where('name', 'Comes back')->exists())->toBeFalse();

        Artisan::call('db:restore', [
            'file' => archives()[0],
            '--force' => true,
            '--skip-safety-backup' => true,
        ]);

        expect(Counterparty::query()->where('name', 'Comes back')->exists())->toBeTrue();
    });

    it('refuses a file that is not one of ours', function (): void {
        File::put(test()->directory.'/not-a-backup.sql.gz', 'plain text, not gzip');

        $code = Artisan::call('db:restore', [
            'file' => test()->directory.'/not-a-backup.sql.gz',
            '--force' => true,
            '--skip-safety-backup' => true,
        ]);

        expect($code)->toBe(1)->and(Artisan::output())->toContain('not a gzip archive');
    });

    // A dump cut short by a full disk looks fine until the day it is needed.
    it('refuses a truncated backup rather than half-restoring', function (): void {
        $path = test()->directory.'/truncated.sql.gz';
        File::put($path, (string) gzencode("-- MySQL dump\nCREATE DATABASE `finance_test`;\nINSERT INTO"));

        $code = Artisan::call('db:restore', [
            'file' => $path,
            '--force' => true,
            '--skip-safety-backup' => true,
        ]);

        expect($code)->toBe(1)->and(Artisan::output())->toContain('incomplete');
    });

    it('refuses a file that does not exist', function (): void {
        expect(Artisan::call('db:restore', ['file' => '/nowhere.sql.gz', '--force' => true]))->toBe(1);
    });

    // The guard that matters most: the thing about to be overwritten is archived first.
    // Directed at the test's own directory, so running the suite does not quietly add
    // files to the real backup folder somebody relies on.
    it('backs up what it is about to replace', function (): void {
        backup();
        $before = count(archives());

        Artisan::call('db:restore', [
            'file' => archives()[0],
            '--force' => true,
            '--backup-path' => test()->directory,
        ]);

        expect(count(archives()))->toBeGreaterThan($before);
    });
});

/*
 * Command lines are readable by anyone who can run `ps`. A backup running when
 * somebody looks should not hand them the database password.
 */
describe('credentials', function (): void {
    it('keeps the password out of the command line', function (): void {
        $credentials = new MysqlCredentials([
            'host' => '127.0.0.1',
            'username' => 'root',
            'password' => 'hunter2',
        ]);

        expect($credentials->argument())->not->toContain('hunter2')
            ->and($credentials->argument())->toStartWith('--defaults-extra-file=');
    });

    it('writes the options file readable only by its owner', function (): void {
        $credentials = new MysqlCredentials(['username' => 'root', 'password' => 'hunter2']);
        $path = str_replace('--defaults-extra-file=', '', $credentials->argument());

        expect(substr(sprintf('%o', fileperms($path)), -3))->toBe('600')
            ->and(File::get($path))->toContain('hunter2');
    });

    it('deletes the options file when it is done with', function (): void {
        $credentials = new MysqlCredentials(['username' => 'root']);
        $path = str_replace('--defaults-extra-file=', '', $credentials->argument());

        unset($credentials);

        expect(File::exists($path))->toBeFalse();
    });
});
