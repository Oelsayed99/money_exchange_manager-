<?php

declare(strict_types=1);

use App\Domain\Money\CurrencyRegistry;
use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
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
    $this->aed = Currency::query()->where('code', 'AED')->sole();

    $this->manager = User::factory()->create();
    $this->manager->assignRole(Role::Owner->value);

    // Holds no role at all. With one role in the system, "not the owner" is what
    // that means, and the assertion worth making is that the gate fails closed.
    $this->stranger = User::factory()->create();
});

/** @param array<string, mixed> $overrides */
function accountPayload(array $overrides = []): array
{
    return [
        'name' => 'Emirates NBD current',
        'type' => AccountType::Bank->value,
        'counterparty_id' => null,
        'owner' => 'Omar',
        'provider' => 'Emirates NBD',
        'identifier' => 'AE070331234567890123456',
        'is_active' => true,
        'sort_order' => 0,
        'currencies' => [],
        ...$overrides,
    ];
}

describe('authorization', function (): void {
    it('requires authentication', function (): void {
        $this->get('/accounts')->assertRedirect('/login');
    });

    it('refuses somebody holding no role, reading or writing', function (): void {
        $this->actingAs($this->stranger)->get('/accounts')->assertForbidden();
        $this->actingAs($this->stranger)->get('/accounts/create')->assertForbidden();
        $this->actingAs($this->stranger)->post('/accounts', accountPayload())->assertForbidden();
    });

    it('refuses a user with no role at all', function (): void {
        $this->actingAs(User::factory()->create())->get('/accounts')->assertForbidden();
    });

    it('exposes no destroy route', function (): void {
        $account = Account::factory()->create();

        expect(Route::has('accounts.destroy'))->toBeFalse();

        $this->actingAs($this->manager)->delete("/accounts/{$account->id}")->assertStatus(405);
    });
});

describe('index', function (): void {
    it('lists accounts with their held currencies', function (): void {
        Account::factory()->holding(['USD' => '1000.50', 'AED' => '3670'])->create(['name' => 'Main safe']);

        $this->actingAs($this->manager)
            ->get('/accounts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('accounts/index')
                ->has('accounts', 1)
                ->where('accounts.0.name', 'Main safe')
                ->has('accounts.0.currencies', 2)
            );
    });

    // R1: an amount must never reach the client as a JSON number.
    it('sends opening balances as strings', function (): void {
        Account::factory()->holding(['USD' => '1000.50'])->create();

        $props = $this->actingAs($this->manager)->get('/accounts')->viewData('page')['props'];
        $held = $props['accounts'][0]['currencies'][0];

        expect($held['opening_balance'])->toBeString()->toBe('1000.50');
        expect(json_encode($held))->toContain('"opening_balance":"1000.50"');
    });

    // The raw account number must not travel to the browser at all.
    it('sends the identifier masked and never in full', function (): void {
        Account::factory()->create(['identifier' => 'AE070331234567890123456']);

        $props = $this->actingAs($this->manager)->get('/accounts')->viewData('page')['props'];

        expect(json_encode($props))->not->toContain('AE070331234567890123456')
            ->and($props['accounts'][0]['masked_identifier'])->toEndWith('3456')
            ->and($props['accounts'][0])->not->toHaveKey('identifier');
    });
});

describe('creating', function (): void {
    it('stores an account with its currencies and opening balances', function (): void {
        $this->actingAs($this->manager)
            ->post('/accounts', accountPayload([
                'currencies' => [
                    ['currency_id' => $this->usd->id, 'opening_balance' => '1000.50'],
                    ['currency_id' => $this->aed->id, 'opening_balance' => '3670'],
                ],
            ]))
            ->assertRedirect('/accounts')
            ->assertSessionHas('success');

        $account = Account::query()->where('name', 'Emirates NBD current')->sole();

        expect($account->openingBalance($this->usd)?->toDisplayString())->toBe('1000.50')
            ->and($account->openingBalance($this->aed)?->toDisplayString())->toBe('3670.00');
    });

    it('links an account to a counterparty', function (): void {
        $party = Counterparty::factory()->create(['name' => 'Salem']);

        $this->actingAs($this->manager)->post('/accounts', accountPayload([
            'type' => AccountType::CreditTrust->value,
            'counterparty_id' => $party->id,
        ]));

        expect(Account::query()->sole()->counterparty?->name)->toBe('Salem');
    });

    it('rejects an unknown account type', function (): void {
        $this->actingAs($this->manager)
            ->post('/accounts', accountPayload(['type' => 'under_the_mattress']))
            ->assertSessionHasErrors('type');
    });

    it('rejects the same currency listed twice', function (): void {
        $this->actingAs($this->manager)
            ->post('/accounts', accountPayload([
                'currencies' => [
                    ['currency_id' => $this->usd->id, 'opening_balance' => '1'],
                    ['currency_id' => $this->usd->id, 'opening_balance' => '2'],
                ],
            ]))
            ->assertSessionHasErrors('currencies.1.currency_id');
    });

    it('rejects an opening balance that is not a plain decimal', function (string $amount): void {
        $this->actingAs($this->manager)
            ->post('/accounts', accountPayload([
                'currencies' => [['currency_id' => $this->usd->id, 'opening_balance' => $amount]],
            ]))
            ->assertSessionHasErrors('currencies.0.opening_balance');
    })->with(['1e5', '1,000.00', 'abc', '', '1.2.3']);

    it('rejects precision beyond what can be stored', function (): void {
        $this->actingAs($this->manager)
            ->post('/accounts', accountPayload([
                'currencies' => [['currency_id' => $this->usd->id, 'opening_balance' => '1.12345678901']],
            ]))
            ->assertSessionHasErrors('currencies.0.opening_balance');
    });

    it('accepts a negative opening balance, which an overdrawn account really has', function (): void {
        $this->actingAs($this->manager)->post('/accounts', accountPayload([
            'currencies' => [['currency_id' => $this->usd->id, 'opening_balance' => '-250.00']],
        ]))->assertSessionHasNoErrors();

        expect(Account::query()->sole()->openingBalance($this->usd)?->toDisplayString())->toBe('-250.00');
    });
});

describe('updating', function (): void {
    it('replaces the held currencies', function (): void {
        $account = Account::factory()->holding(['USD' => '100', 'AED' => '200'])->create();

        $this->actingAs($this->manager)->put("/accounts/{$account->id}", accountPayload([
            'currencies' => [['currency_id' => $this->aed->id, 'opening_balance' => '999']],
        ]));

        $account->refresh();

        expect($account->supports($this->usd))->toBeFalse()
            ->and($account->openingBalance($this->aed)?->toDisplayString())->toBe('999.00');
    });

    it('records the change in the audit trail', function (): void {
        $account = Account::factory()->create(['name' => 'Before']);

        $this->actingAs($this->manager)->put("/accounts/{$account->id}", accountPayload(['name' => 'After']));

        $entry = $account->auditLogs()->where('event', 'updated')->first();

        expect($entry?->new_values['name'])->toBe('After')
            ->and($entry?->actor_label)->toBe($this->manager->email);
    });
});
