<?php

declare(strict_types=1);

use App\Domain\Money\CurrencyRegistry;
use App\Enums\BalanceBucket;
use App\Enums\CounterpartyType;
use App\Enums\Role;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();

    $this->usd = Currency::query()->where('code', 'USD')->sole();
    $this->egp = Currency::query()->where('code', 'EGP')->sole();

    $this->manager = User::factory()->create();
    $this->manager->assignRole(Role::Administrator->value);

    $this->viewer = User::factory()->create();
    $this->viewer->assignRole(Role::Viewer->value);
});

/** @param array<string, mixed> $overrides */
function partyPayload(array $overrides = []): array
{
    return [
        'name' => 'Salem Abu Rashed',
        'type' => CounterpartyType::Customer->value,
        'phone' => '+201001234567',
        'email' => 'abdo@example.com',
        'country' => 'EG',
        'preferred_currency_id' => null,
        'is_active' => true,
        'positions' => [],
        ...$overrides,
    ];
}

describe('authorization', function (): void {
    it('requires authentication', function (): void {
        $this->get('/counterparties')->assertRedirect('/login');
    });

    it('lets a viewer read but not create', function (): void {
        $this->actingAs($this->viewer)->get('/counterparties')->assertOk();
        $this->actingAs($this->viewer)->post('/counterparties', partyPayload())->assertForbidden();
    });

    it('exposes no destroy route', function (): void {
        $party = Counterparty::factory()->create();

        expect(Route::has('counterparties.destroy'))->toBeFalse();

        $this->actingAs($this->manager)->delete("/counterparties/{$party->id}")->assertStatus(405);
    });
});

describe('index', function (): void {
    it('sends the four buckets to the client', function (): void {
        $this->actingAs($this->manager)
            ->get('/counterparties')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('counterparties/index')
                ->has('buckets', 4)
                ->where('buckets.0.value', 'custody')
                ->where('buckets.0.isAsset', true)
                ->where('buckets.2.value', 'payable')
                ->where('buckets.2.isAsset', false)
            );
    });

    // The requirement that makes Section 5 real: a party who owes money and holds
    // money at once must be shown as two figures, never one.
    it('reports a party who both owes and holds without netting', function (): void {
        Counterparty::factory()->withPositions([
            'custody' => ['USD' => '5000'],
            'receivable' => ['USD' => '1200'],
            'payable' => ['USD' => '300'],
        ])->create(['name' => 'Salem']);

        $props = $this->actingAs($this->manager)->get('/counterparties')->viewData('page')['props'];
        $positions = collect($props['counterparties'][0]['positions'])->keyBy('bucket');

        expect($positions['custody']['amount'])->toBe('5000.00')
            ->and($positions['receivable']['amount'])->toBe('1200.00')
            ->and($positions['payable']['amount'])->toBe('300.00');

        // No net figure of 900 (1200 - 300), and no combined total, anywhere.
        expect($props['counterparties'][0])->not->toHaveKey('balance')
            ->and($props['counterparties'][0])->not->toHaveKey('net');
    });

    it('sends amounts as strings, never JSON numbers', function (): void {
        Counterparty::factory()->withPositions(['custody' => ['USD' => '5000.25']])->create();

        $props = $this->actingAs($this->manager)->get('/counterparties')->viewData('page')['props'];

        expect($props['counterparties'][0]['positions'][0]['amount'])->toBeString();
        expect(json_encode($props['counterparties'][0]['positions'][0]))->toContain('"amount":"5000.25"');
    });
});

describe('creating', function (): void {
    it('stores a party with positions in several buckets and currencies', function (): void {
        $this->actingAs($this->manager)
            ->post('/counterparties', partyPayload([
                'positions' => [
                    ['bucket' => 'custody', 'currency_id' => $this->usd->id, 'amount' => '5000'],
                    ['bucket' => 'receivable', 'currency_id' => $this->egp->id, 'amount' => '14890'],
                ],
            ]))
            ->assertRedirect('/counterparties')
            ->assertSessionHas('success');

        $party = Counterparty::query()->where('name', 'Salem Abu Rashed')->sole();

        expect($party->openingBalance(BalanceBucket::Custody, $this->usd)?->toDisplayString())->toBe('5000.00')
            ->and($party->openingBalance(BalanceBucket::Receivable, $this->egp)?->toDisplayString())->toBe('14890.00')
            ->and($party->openingBalance(BalanceBucket::Payable, $this->usd))->toBeNull();
    });

    it('uppercases the country code', function (): void {
        $this->actingAs($this->manager)->post('/counterparties', partyPayload(['country' => 'eg']));

        expect(Counterparty::query()->sole()->country)->toBe('EG');
    });

    // Section 5: a negative receivable is a payable, and the message says so.
    it('refuses a negative position and names the bucket it belongs in', function (): void {
        $this->actingAs($this->manager)
            ->post('/counterparties', partyPayload([
                'positions' => [['bucket' => 'receivable', 'currency_id' => $this->usd->id, 'amount' => '-100']],
            ]))
            ->assertSessionHasErrors('positions.0.amount');

        expect(session('errors')?->first('positions.0.amount'))->toContain('Payable');
    });

    it('refuses a negative position in every bucket', function (string $bucket): void {
        $this->actingAs($this->manager)
            ->post('/counterparties', partyPayload([
                'positions' => [['bucket' => $bucket, 'currency_id' => $this->usd->id, 'amount' => '-1']],
            ]))
            ->assertSessionHasErrors('positions.0.amount');
    })->with(BalanceBucket::values());

    it('refuses the same bucket and currency twice', function (): void {
        $this->actingAs($this->manager)
            ->post('/counterparties', partyPayload([
                'positions' => [
                    ['bucket' => 'custody', 'currency_id' => $this->usd->id, 'amount' => '1'],
                    ['bucket' => 'custody', 'currency_id' => $this->usd->id, 'amount' => '2'],
                ],
            ]))
            ->assertSessionHasErrors('positions.1.amount');
    });

    it('allows the same currency in different buckets', function (): void {
        $this->actingAs($this->manager)
            ->post('/counterparties', partyPayload([
                'positions' => [
                    ['bucket' => 'custody', 'currency_id' => $this->usd->id, 'amount' => '1'],
                    ['bucket' => 'payable', 'currency_id' => $this->usd->id, 'amount' => '2'],
                ],
            ]))
            ->assertSessionHasNoErrors();
    });

    it('rejects an unrecognised bucket', function (): void {
        $this->actingAs($this->manager)
            ->post('/counterparties', partyPayload([
                'positions' => [['bucket' => 'vibes', 'currency_id' => $this->usd->id, 'amount' => '1']],
            ]))
            ->assertSessionHasErrors('positions.0.bucket');
    });

    it('rejects an amount that is not a plain decimal', function (string $amount): void {
        $this->actingAs($this->manager)
            ->post('/counterparties', partyPayload([
                'positions' => [['bucket' => 'custody', 'currency_id' => $this->usd->id, 'amount' => $amount]],
            ]))
            ->assertSessionHasErrors('positions.0.amount');
    })->with(['1e5', '1,000.00', 'abc', '1.2.3']);
});

describe('updating', function (): void {
    it('removes a position dropped from the form', function (): void {
        $party = Counterparty::factory()->withPositions([
            'custody' => ['USD' => '100'],
            'payable' => ['USD' => '50'],
        ])->create();

        $this->actingAs($this->manager)->put("/counterparties/{$party->id}", partyPayload([
            'positions' => [['bucket' => 'custody', 'currency_id' => $this->usd->id, 'amount' => '100']],
        ]));

        $party->refresh();

        expect($party->openingBalance(BalanceBucket::Custody, $this->usd)?->toDisplayString())->toBe('100.00')
            ->and($party->openingBalance(BalanceBucket::Payable, $this->usd))->toBeNull();
    });

    it('records the change in the audit trail', function (): void {
        $party = Counterparty::factory()->create(['name' => 'Before']);

        $this->actingAs($this->manager)->put("/counterparties/{$party->id}", partyPayload(['name' => 'After']));

        expect($party->auditLogs()->where('event', 'updated')->first()?->new_values['name'])->toBe('After');
    });
});
