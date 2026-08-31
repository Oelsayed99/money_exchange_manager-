<?php

declare(strict_types=1);

use App\Domain\Money\CurrencyRegistry;
use App\Enums\CounterpartyType;
use App\Enums\Role;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();

    $this->usd = Currency::query()->where('code', 'USD')->sole();
    $this->egp = Currency::query()->where('code', 'EGP')->sole();

    $this->manager = User::factory()->create();
    $this->manager->assignRole(Role::Owner->value);

    // Holds no role at all: the gate has to fail closed.
    $this->stranger = User::factory()->create();
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

    it('refuses somebody holding no role, reading or writing', function (): void {
        $this->actingAs($this->stranger)->get('/counterparties')->assertForbidden();
        $this->actingAs($this->stranger)->post('/counterparties', partyPayload())->assertForbidden();
    });

    it('exposes no destroy route', function (): void {
        $party = Counterparty::factory()->create();

        expect(Route::has('counterparties.destroy'))->toBeFalse();

        $this->actingAs($this->manager)->delete("/counterparties/{$party->id}")->assertStatus(405);
    });
});

describe('index', function (): void {
    it('reports one signed balance per currency', function (): void {
        Counterparty::factory()->withPositions(['USD' => '-5000'])->create(['name' => 'Salem']);

        $props = $this->actingAs($this->manager)->get('/counterparties')->viewData('page')['props'];
        $row = $props['counterparties'][0];

        expect($row['positions'])->toHaveCount(1)
            ->and($row['positions'][0]['amount'])->toBe('-5000.00');
    });

    // The owner's objection, in a test: they cannot both owe us and be owed by us. It
    // is one figure and its sign.
    it('sends no second column for the other side', function (): void {
        Counterparty::factory()->withPositions(['USD' => '5000'])->create();

        $row = $this->actingAs($this->manager)->get('/counterparties')->viewData('page')['props']['counterparties'][0];

        expect($row)->not->toHaveKey('ours')
            ->and($row)->not->toHaveKey('theirs')
            ->and($row['positions'][0])->not->toHaveKey('bucket');
    });

    it('sends amounts as strings, never JSON numbers', function (): void {
        Counterparty::factory()->withPositions(['USD' => '5000.25'])->create();

        $props = $this->actingAs($this->manager)->get('/counterparties')->viewData('page')['props'];

        expect($props['counterparties'][0]['positions'][0]['amount'])->toBeString();
        expect(json_encode($props['counterparties'][0]['positions'][0]))->toContain('"amount":"5000.25"');
    });
});

describe('creating', function (): void {
    it('stores a party with a position in several currencies', function (): void {
        $this->actingAs($this->manager)
            ->post('/counterparties', partyPayload([
                'positions' => [
                    ['currency_id' => $this->usd->id, 'amount' => '5000'],
                    ['currency_id' => $this->egp->id, 'amount' => '-120000'],
                ],
            ]))
            ->assertRedirect('/counterparties');

        $party = Counterparty::query()->sole();

        expect($party->openingPositions())->toBe(['USD' => '5000.00', 'EGP' => '-120000.00']);
    });

    it('uppercases the country code', function (): void {
        $this->actingAs($this->manager)
            ->post('/counterparties', partyPayload(['country' => 'eg']))
            ->assertRedirect();

        expect(Counterparty::query()->sole()->country)->toBe('EG');
    });

    // A negative figure is not an error here, it is the other half of the model.
    it('accepts a negative position', function (): void {
        $this->actingAs($this->manager)
            ->post('/counterparties', partyPayload([
                'positions' => [['currency_id' => $this->usd->id, 'amount' => '-5000']],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(Counterparty::query()->sole()->openingBalance($this->usd)?->toDisplayString())->toBe('-5000.00');
    });

    it('refuses the same currency twice', function (): void {
        $this->actingAs($this->manager)
            ->post('/counterparties', partyPayload([
                'positions' => [
                    ['currency_id' => $this->usd->id, 'amount' => '100'],
                    ['currency_id' => $this->usd->id, 'amount' => '200'],
                ],
            ]))
            ->assertSessionHasErrors('positions.1.amount');
    });

    it('rejects an amount that is not a plain decimal', function (string $amount): void {
        $this->actingAs($this->manager)
            ->post('/counterparties', partyPayload([
                'positions' => [['currency_id' => $this->usd->id, 'amount' => $amount]],
            ]))
            ->assertSessionHasErrors('positions.0.amount');
    })->with(['1e5', '1,000', 'abc', '']);
});

describe('updating', function (): void {
    it('removes a position dropped from the form', function (): void {
        $party = Counterparty::factory()->withPositions(['USD' => '100', 'EGP' => '-50'])->create();

        $this->actingAs($this->manager)->put("/counterparties/{$party->id}", partyPayload([
            'name' => $party->name,
            'positions' => [['currency_id' => $this->usd->id, 'amount' => '100']],
        ]));

        $party->refresh();

        expect($party->openingBalance($this->usd)?->toDisplayString())->toBe('100.00')
            ->and($party->openingBalance($this->egp))->toBeNull();
    });

    it('records the change in the audit trail', function (): void {
        $party = Counterparty::factory()->create(['name' => 'Before']);

        $this->actingAs($this->manager)->put("/counterparties/{$party->id}", partyPayload(['name' => 'After']));

        expect($party->auditLogs()->where('event', 'updated')->first()?->new_values['name'])->toBe('After');
    });
});
