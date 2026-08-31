<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Money\CurrencyRegistry;
use App\Domain\Reconciliation\ReconciliationService;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\Reconciliation;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * No screen may ask the database more questions because it has more rows to show.
 *
 * An N+1 is invisible while the data is small — every test passes, every page is fast,
 * and the bill arrives on the day the ledger is worth reading. So this measures each
 * screen twice, at two sizes, and requires the same number of queries both times.
 *
 * It has already caught two: a list that ran a query per row, and a reconciliation page
 * that recomputed drift one reconciliation at a time.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->safe = Account::factory()->create(['name' => 'Safe']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::Owner->value);

    $this->rules = app(PostingRules::class);
    $this->posting = app(PostingService::class);
});

function addVolume(int $parties, int $movementsEach): void
{
    $test = test();

    foreach (range(1, $parties) as $p) {
        $party = Counterparty::factory()->create();

        foreach (range(1, $movementsEach) as $m) {
            $test->posting->post($test->rules->build(new TransactionInput(
                type: $m % 2 === 0 ? TransactionType::In : TransactionType::Out,
                currency: $test->egp,
                amount: $test->egp->money((string) (1000 + $m)),
                occurredAt: new DateTimeImmutable('2026-06-0'.(($m % 9) + 1)),
                account: $test->safe,
                counterparty: $party,
            )));
        }
    }

    // One count per account, currency and day, so each needs its own date.
    $day = Reconciliation::query()->count();

    foreach (range(1, $parties) as $ignored) {
        app(ReconciliationService::class)->record(
            $test->safe,
            $test->egp,
            Carbon::parse('2025-01-01')->addDays($day++),
            $test->egp->money('1000'),
        );
    }
}

function queriesFor(string $path): int
{
    $count = 0;

    DB::listen(function () use (&$count): void {
        $count++;
    });

    test()->actingAs(test()->admin)->get($path)->assertOk();

    DB::flushQueryLog();

    return $count;
}

it('does not ask more of the database as the data grows', function (string $path): void {
    addVolume(2, 2);

    $party = Counterparty::query()->firstOrFail();
    $route = str_replace('{counterparty}', (string) $party->id, $path);

    // One throwaway request first. Caches that load once per process — the permission
    // table among them — otherwise make the first measurement four queries heavier than
    // every one after it, which looks exactly like the thing this test is hunting.
    queriesFor($route);

    $small = queriesFor($route);

    // Five times the parties, five times the transactions, five times the counts.
    addVolume(8, 2);

    expect(queriesFor($route))->toBe(
        $small,
        "{$route} asks the database more questions when there is more to show — a query per row somewhere.",
    );
})->with([
    '/dashboard',
    '/transactions',
    '/counterparties',
    '/reconciliations',
    '/audit',
    '/movements',
    '/exchange',
    '/counterparties/{counterparty}/statement',
]);

/*
 * Both of these hold a lookup for the life of a request. Neither was registered, so the
 * container built a fresh one on every resolution and each reloaded its table — which
 * showed up as `select * from currencies` fifteen times on a single page.
 */
it('keeps one currency registry per request', function (): void {
    expect(app(CurrencyRegistry::class))->toBe(app(CurrencyRegistry::class));
});

it('keeps one ledger account resolver per request', function (): void {
    expect(app(LedgerAccountResolver::class))->toBe(app(LedgerAccountResolver::class));
});
