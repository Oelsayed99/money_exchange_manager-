<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Money\CurrencyRegistry;
use App\Enums\BalanceBucket;
use App\Enums\CounterpartyType;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\LedgerBalance;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Collection;

/**
 * A position typed on a counterparty is a transaction.
 *
 * It used to be a note on the record: no entry, no date, nothing in the transaction
 * list, and a warning on the statement admitting as much. Every other figure in this
 * application has a transaction behind it and these were the exception.
 *
 * The ledger cannot un-post, so changing a figure is not an edit — it is a second
 * transaction for the difference. Both stay, and the trail shows the figure was raised,
 * when, and by whom.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->egp = Currency::query()->where('code', 'EGP')->sole();

    $this->manager = User::factory()->create();
    $this->manager->assignRole(Role::Administrator->value);
});

/** @param  list<array<string, string|int>>  $positions */
function saveParty(?Counterparty $party, array $positions): void
{
    $payload = [
        'name' => $party->name ?? 'Salem',
        'type' => CounterpartyType::Customer->value,
        'phone' => null,
        'email' => null,
        'country' => null,
        'preferred_currency_id' => null,
        'is_active' => true,
        'positions' => $positions,
    ];

    $response = $party === null
        ? test()->actingAs(test()->manager)->post('/counterparties', $payload)
        : test()->actingAs(test()->manager)->put("/counterparties/{$party->id}", $payload);

    $response->assertRedirect();
}

function openingsOf(Counterparty $party): Collection
{
    return Transaction::query()
        ->where('counterparty_id', $party->id)
        ->where('type', TransactionType::OpeningBalance)
        ->orderBy('id')
        ->get();
}

/**
 * The credit position, in the account's own terms.
 *
 * A liability reads positive when the business owes it — the balance is kept in the
 * account's natural direction rather than signed against a single convention.
 */
function creditPosition(Counterparty $party, Currency $currency): string
{
    $account = app(LedgerAccountResolver::class)->forBucket(BalanceBucket::CreditTrust, $party, $currency);

    return LedgerBalance::query()->where('ledger_account_id', $account->id)->sole()->confirmed()->toDisplayString();
}

it('posts a transaction when a position is first declared', function (): void {
    saveParty(null, [['bucket' => 'credit_trust', 'currency_id' => $this->egp->id, 'amount' => '899510']]);

    $party = Counterparty::query()->sole();
    $openings = openingsOf($party);

    expect($openings)->toHaveCount(1)
        ->and(creditPosition($party, $this->egp))->toBe('899510.00');
});

// The whole point of the request: it has a date, and you can find it.
it('dates it and puts it in the transaction list', function (): void {
    saveParty(null, [['bucket' => 'credit_trust', 'currency_id' => $this->egp->id, 'amount' => '899510']]);

    $party = Counterparty::query()->sole();

    expect(openingsOf($party)->first()->occurred_at)->not->toBeNull();

    $props = $this->actingAs($this->manager)->get('/transactions')->viewData('page')['props'];
    $rows = collect($props['transactions']['data']);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['type'])->toBe('opening_balance')
        ->and($rows->first()['counterparty']['name'])->toBe($party->name);
});

it('posts only the difference when a figure is raised', function (): void {
    saveParty(null, [['bucket' => 'credit_trust', 'currency_id' => $this->egp->id, 'amount' => '899510']]);
    $party = Counterparty::query()->sole();

    saveParty($party, [['bucket' => 'credit_trust', 'currency_id' => $this->egp->id, 'amount' => '950000']]);

    $openings = openingsOf($party);

    expect($openings)->toHaveCount(2)
        ->and($openings->last()->legs)->toHaveCount(1)
        ->and(creditPosition($party, $this->egp))->toBe('950000.00');

    // Two transactions of 899,510 and 50,490 — not one of 950,000 replacing the other.
    expect($openings->first()->entries->first()->amount->toDisplayString())->toBe('899510.00')
        ->and($openings->last()->entries->first()->amount->toDisplayString())->toBe('50490.00');
});

// The ledger has no way to un-post, so lowering a figure is a posting the other way.
it('posts the other way when a figure is lowered', function (): void {
    saveParty(null, [['bucket' => 'credit_trust', 'currency_id' => $this->egp->id, 'amount' => '899510']]);
    $party = Counterparty::query()->sole();

    saveParty($party, [['bucket' => 'credit_trust', 'currency_id' => $this->egp->id, 'amount' => '800000']]);

    expect(openingsOf($party))->toHaveCount(2)
        ->and(creditPosition($party, $this->egp))->toBe('800000.00');
});

it('unwinds the position when it is removed, and only then forgets it', function (): void {
    saveParty(null, [['bucket' => 'credit_trust', 'currency_id' => $this->egp->id, 'amount' => '899510']]);
    $party = Counterparty::query()->sole();

    saveParty($party, []);

    expect(openingsOf($party))->toHaveCount(2)
        ->and(creditPosition($party, $this->egp))->toBe('0.00')
        ->and($party->openingBalances()->count())->toBe(0);
});

// Saving a counterparty whose figures nobody touched must not write anything.
it('posts nothing when a figure is unchanged', function (): void {
    saveParty(null, [['bucket' => 'credit_trust', 'currency_id' => $this->egp->id, 'amount' => '899510']]);
    $party = Counterparty::query()->sole();

    saveParty($party, [['bucket' => 'credit_trust', 'currency_id' => $this->egp->id, 'amount' => '899510']]);

    expect(openingsOf($party))->toHaveCount(1);
});

it('handles an asset position the same way, in the opposite direction', function (): void {
    saveParty(null, [['bucket' => 'receivable', 'currency_id' => $this->egp->id, 'amount' => '14890']]);

    $party = Counterparty::query()->sole();
    $account = app(LedgerAccountResolver::class)->forBucket(BalanceBucket::Receivable, $party, $this->egp);
    $balance = LedgerBalance::query()->where('ledger_account_id', $account->id)->sole()->confirmed();

    expect($balance->toDisplayString())->toBe('14890.00');
});

it('leaves a ledger that balances after every change', function (): void {
    saveParty(null, [
        ['bucket' => 'credit_trust', 'currency_id' => $this->egp->id, 'amount' => '899510'],
        ['bucket' => 'receivable', 'currency_id' => $this->egp->id, 'amount' => '14890'],
    ]);

    $party = Counterparty::query()->sole();

    saveParty($party, [['bucket' => 'credit_trust', 'currency_id' => $this->egp->id, 'amount' => '500000']]);

    $this->artisan('ledger:verify --transactions')->assertExitCode(0);
});

// The statement's warning existed because these were not in the ledger. Now they are.
it('stops warning on the statement once the position is posted', function (): void {
    saveParty(null, [['bucket' => 'credit_trust', 'currency_id' => $this->egp->id, 'amount' => '899510']]);
    $party = Counterparty::query()->sole();

    $props = $this->actingAs($this->manager)
        ->get("/counterparties/{$party->id}/statement?currency=EGP")
        ->viewData('page')['props'];

    expect($props['statement']['declared_opening'])->toBe([]);
});
