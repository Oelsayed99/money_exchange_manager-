<?php

declare(strict_types=1);

use App\Domain\Tenancy\CurrentBusiness;
use App\Models\Business;
use App\Models\Concerns\BelongsToBusiness;
use App\Models\Currency;
use Database\Seeders\CurrencySeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\BusinessIsolationTest;

/**
 * Guards that a future change cannot quietly step around.
 *
 * {@see BusinessIsolationTest} proves the isolation that exists today.
 * These prove that the *mechanisms* enforcing it are still in place — that nobody has
 * added a model without the trait, a raw query without the scope, or an `exists` rule
 * that reads straight through to the table.
 *
 * Structural rather than behavioural on purpose. A leak arrives as a new file that
 * forgot something, and no behavioural test covers a file nobody has written yet.
 */

/** @return list<string> */
function phpFilesIn(string $directory): array
{
    $path = base_path($directory);

    if (! is_dir($path)) {
        return [];
    }

    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * The tables holding one business's things.
 *
 * Named here rather than derived, so that adding a table is a decision somebody makes
 * about this list rather than something that happens silently.
 *
 * @var list<string>
 */
const SCOPED_TABLES = [
    'currencies', 'accounts', 'counterparties', 'counterparty_opening_balances',
    'ledger_accounts', 'ledger_balances', 'ledger_entries', 'transactions',
    'transaction_legs', 'reconciliations', 'audit_logs',
];

it('carries a business on every table that holds something', function (string $table): void {
    expect(Schema::hasColumn($table, 'business_id'))->toBeTrue(
        "[{$table}] holds one business's data and has no business_id."
    );
})->with(SCOPED_TABLES);

it('has a model for every scoped table, and every one of them uses the trait', function (): void {
    $models = [];

    foreach (phpFilesIn('app/Models') as $file) {
        $class = 'App\\Models\\'.Str::of($file)->afterLast('/')->before('.php')->toString();

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        $models[(new $class)->getTable()] = $class;
    }

    $missing = [];

    foreach (SCOPED_TABLES as $table) {
        $class = $models[$table] ?? null;

        if ($class === null) {
            continue;
        }

        if (! in_array(BelongsToBusiness::class, class_uses_recursive($class), true)) {
            $missing[] = $class;
        }
    }

    expect($missing)->toBe([], 'These models hold a business\'s data without the BelongsToBusiness trait.');
});

/*
 * The route a global scope cannot cover.
 *
 * `DB::table('ledger_entries')` returns every business's entries and looks completely
 * ordinary. ScopedQuery exists so the filter is applied where the table is named; this
 * asserts nothing in the domain layer goes round it.
 */
it('lets nothing in the domain reach a table without the scope', function (): void {
    $offenders = [];

    foreach (phpFilesIn('app/Domain') as $file) {
        if (str_ends_with($file, 'ScopedQuery.php')) {
            continue;
        }

        if (str_contains((string) file_get_contents($file), 'DB::table(')) {
            $offenders[] = Str::after($file, base_path().'/');
        }
    }

    expect($offenders)->toBe(
        [],
        'These read straight from a table. Use ScopedQuery so the business filter cannot be forgotten.'
    );
});

/*
 * `Rule::exists` builds its own query against the table, with no model and no scope.
 * It will confirm that another business's counterparty exists, and a form that only
 * checks existence then accepts it — which is exactly what happened before Owned.
 */
it('validates existence within the business, never across it', function (): void {
    $offenders = [];

    foreach ([...phpFilesIn('app/Http'), ...phpFilesIn('app/Domain')] as $file) {
        if (str_ends_with($file, 'Owned.php')) {
            continue;
        }

        if (str_contains((string) file_get_contents($file), 'Rule::exists(')) {
            $offenders[] = Str::after($file, base_path().'/');
        }
    }

    expect($offenders)->toBe(
        [],
        'These confirm a row exists anywhere rather than in this business. Use Owned::exists().'
    );
});

it('cascades a business\'s data away with the business', function (string $table): void {
    $constraints = collect(DB::select(
        'SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
        [$table, $table.'_business_id_foreign'],
    ));

    expect($constraints)->toHaveCount(1)
        ->and($constraints->first()->DELETE_RULE)->toBe('CASCADE');
})->with(SCOPED_TABLES);

it('keeps a business\'s name to itself', function (): void {
    // Two businesses may both hold a currency called EGP and a ledger account coded
    // cash:system. Global uniques here would mean the second business to sign up
    // simply could not save.
    $business = Business::factory()->create();

    $duplicate = app(CurrentBusiness::class)->actingAs(
        $business,
        function () {
            (new CurrencySeeder)->run();

            return Currency::query()->where('code', 'EGP')->exists();
        },
    );

    expect($duplicate)->toBeTrue();
});
