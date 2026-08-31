<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Money\CurrencyRegistry;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Two figures on the list, four buckets underneath.
 *
 * ADR 0007 forbids one balance per party because netting a receivable against a payable
 * gives a number that is right in total and useless in practice. That prohibition is
 * about the two *sides*. Adding custody to receivable — both our money with them —
 * summarises one side and loses nothing that the statement does not still hold.
 *
 * These tests exist to keep those two apart: the sum within a side is asserted, and the
 * absence of any sum across them is asserted just as hard.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->usd = Currency::query()->where('code', 'USD')->sole();

    $this->safe = Account::factory()->create(['name' => 'Main safe']);

    $this->manager = User::factory()->create();
    $this->manager->assignRole(Role::Owner->value);

    $this->client = Counterparty::factory()->create(['name' => 'Salem']);
});

/** Post a movement of a given type, which is what puts a party into a bucket.
 *  Named for this file: Pest test helpers share one global namespace. */
function postToBucket(TransactionType $type, Counterparty $party, Currency $currency, string $amount, Account $account): void
{
    app(PostingService::class)->post(app(PostingRules::class)->build(new TransactionInput(
        type: $type,
        currency: $currency,
        amount: $currency->money($amount),
        occurredAt: new DateTimeImmutable('2026-06-10'),
        account: $account,
        counterparty: $party,
    )));
}

/** @return array<string, mixed> */
function standingRow(Counterparty $party): array
{
    $props = test()->actingAs(test()->manager)->get('/counterparties')->viewData('page')['props'];

    $row = collect($props['counterparties'])->firstWhere('id', $party->id);

    return is_array($row) ? $row : [];
}

/**
 * One signed balance per party per currency.
 *
 * The owner's objection to two columns was exact: a party cannot both owe us and be
 * owed by us. It is one figure and its sign — positive means they owe us, negative that
 * we are holding theirs. See ADR 0032.
 */
it('nets everything into one figure per currency', function (): void {
    // They gave us 899,510 and we gave them back 14,890.
    postToBucket(TransactionType::In, $this->client, $this->egp, '899510', $this->safe);
    postToBucket(TransactionType::Out, $this->client, $this->egp, '14890', $this->safe);

    $standing = collect(standingRow($this->client)['standings'])->firstWhere('code', 'EGP');

    expect($standing['balance'])->toBe('-884620.00');
});

it('reads positive when we have paid out more than we took in', function (): void {
    postToBucket(TransactionType::Out, $this->client, $this->egp, '50000', $this->safe);
    postToBucket(TransactionType::In, $this->client, $this->egp, '20000', $this->safe);

    $standing = collect(standingRow($this->client)['standings'])->firstWhere('code', 'EGP');

    expect($standing['balance'])->toBe('30000.00');
});

// There is no second column, and no way to ask for one.
it('sends one figure and no other side', function (): void {
    postToBucket(TransactionType::In, $this->client, $this->egp, '899510', $this->safe);

    $row = standingRow($this->client);
    $standing = collect($row['standings'])->firstWhere('code', 'EGP');

    expect($standing)->toHaveKey('balance')
        ->and($standing)->not->toHaveKey('ours')
        ->and($standing)->not->toHaveKey('theirs')
        ->and($standing)->not->toHaveKey('buckets');
});

it('reports each currency on its own', function (): void {
    postToBucket(TransactionType::In, $this->client, $this->egp, '899510', $this->safe);
    postToBucket(TransactionType::Out, $this->client, $this->usd, '2000', $this->safe);

    $standings = collect(standingRow($this->client)['standings'])->keyBy('code');

    expect($standings)->toHaveCount(2)
        ->and($standings['EGP']['balance'])->toBe('-899510.00')
        ->and($standings['USD']['balance'])->toBe('2000.00');
});

it('sends every figure as a string, never a JSON number', function (): void {
    postToBucket(TransactionType::In, $this->client, $this->egp, '899510.25', $this->safe);

    $standing = collect(standingRow($this->client)['standings'])->firstWhere('code', 'EGP');

    expect($standing['balance'])->toBeString()
        ->and(json_encode($standing))->toContain('"balance":"-899510.25"');
});

// The figures come from the ledger. A position somebody typed on the record but never
// posted is not in them — which is why the row still carries its declared openings, so
// the interface can say so rather than quietly showing zero.
it('reads the ledger, not the declared openings', function (): void {
    $party = Counterparty::factory()->withPositions(['EGP' => '-899510'])->create(['name' => 'Declared only']);

    $row = standingRow($party);

    expect($row['standings'])->toBe([])
        ->and($row['positions'])->toHaveCount(1);
});

// The shape that turned /transactions into fifty-eight queries. A list of parties is
// exactly where asking per row would come back.
it('asks the ledger once however many parties there are', function (): void {
    foreach (range(1, 6) as $n) {
        $party = Counterparty::factory()->create(['name' => "Party {$n}"]);
        postToBucket(TransactionType::In, $party, $this->egp, '1000', $this->safe);
    }

    DB::enableQueryLog();
    $this->actingAs($this->manager)->get('/counterparties')->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $balanceQueries = collect($queries)->filter(
        fn (array $query): bool => str_contains($query['query'], 'ledger_balances')
    );

    expect($balanceQueries)->toHaveCount(1);
});
