<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Money\CurrencyRegistry;
use App\Enums\LedgerAccountSubkind;
use App\Enums\Permission;
use App\Enums\ProfitStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Currency;
use App\Models\LedgerBalance;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->usd = Currency::query()->where('code', 'USD')->sole();

    $this->egpSafe = Account::factory()->create(['name' => 'EGP safe']);
    $this->usdSafe = Account::factory()->create(['name' => 'USD safe']);

    $this->operator = User::factory()->create();
    $this->operator->assignRole(Role::Operator->value);

    $this->viewer = User::factory()->create();
    $this->viewer->assignRole(Role::Viewer->value);
});

/** The real deal: 2,574,000 EGP in for 50,000 USD out, cost 51.20. */
function dealPayload(array $overrides = []): array
{
    $test = test();

    return [
        'occurred_at' => '2026-06-16',
        'received_currency_id' => $test->egp->id,
        'received_amount' => '2574000',
        'received_into_id' => $test->egpSafe->id,
        'delivered_currency_id' => $test->usd->id,
        'delivered_amount' => '50000',
        'delivered_from_id' => $test->usdSafe->id,
        'profit_method' => 'rate_difference',
        'cost_rate' => '51.20',
        ...$overrides,
    ];
}

describe('authorization', function (): void {
    it('requires authentication', function (): void {
        $this->get('/exchange')->assertRedirect('/login');
    });

    it('refuses a viewer', function (): void {
        $this->actingAs($this->viewer)->get('/exchange')->assertForbidden();
        $this->actingAs($this->viewer)->post('/exchange', dealPayload())->assertForbidden();
    });

    it('lets an operator record a deal', function (): void {
        $this->actingAs($this->operator)->get('/exchange')->assertOk();
    });

    // Recording is one permission; committing to the ledger is another.
    it('lets someone preview without being able to post', function (): void {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::RecordTransactions->value);

        $this->actingAs($user)->postJson('/exchange/preview', dealPayload())->assertOk();
        $this->actingAs($user)->post('/exchange', dealPayload())->assertForbidden();
    });
});

describe('the form', function (): void {
    it('offers everything needed to record a deal', function (): void {
        $this->actingAs($this->operator)
            ->get('/exchange')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('exchange/create')
                ->has('currencies', 4)
                ->has('accounts', 2)
                ->has('profitMethods', 5)
                ->has('spreadTypes', 3)
                ->has('methods', 5)
            );
    });

    // Section 3: a spread is never a bare number.
    it('sends what each spread type means', function (): void {
        $props = $this->actingAs($this->operator)->get('/exchange')->viewData('page')['props'];

        expect(collect($props['spreadTypes'])->pluck('value')->all())
            ->toBe(['per_unit', 'percentage', 'fixed_amount']);
    });
});

describe('the live preview', function (): void {
    it('returns the calculation without recording anything', function (): void {
        $response = $this->actingAs($this->operator)->postJson('/exchange/preview', dealPayload());

        $response->assertOk()
            ->assertJsonPath('customer_rate', '51.480000000000')
            ->assertJsonPath('gross_profit.amount', '14000.00')
            ->assertJsonPath('net_profit.amount', '14000.00')
            ->assertJsonPath('is_loss', false);

        expect(Transaction::query()->count())->toBe(0);
    });

    // Risk R1 again, at a different boundary: the preview is JSON, and every amount
    // in it is still a string.
    it('sends every amount as a string', function (): void {
        $json = $this->actingAs($this->operator)->postJson('/exchange/preview', dealPayload())->content();

        expect($json)->toContain('"amount":"14000.00"')
            ->and($json)->not->toContain('"amount":14000');
    });

    it('flags a loss', function (): void {
        $this->actingAs($this->operator)
            ->postJson('/exchange/preview', dealPayload(['cost_rate' => '52.00']))
            ->assertJsonPath('is_loss', true)
            ->assertJsonPath('gross_profit.amount', '-26000.00');
    });

    // The same number, two meanings, two answers.
    it('distinguishes a per-unit spread from a percentage', function (): void {
        $perUnit = $this->actingAs($this->operator)->postJson('/exchange/preview', dealPayload([
            'profit_method' => 'percentage', 'cost_rate' => null,
            'spread_type' => 'per_unit', 'spread_value' => '0.02',
        ]))->json('gross_profit.amount');

        $percentage = $this->actingAs($this->operator)->postJson('/exchange/preview', dealPayload([
            'profit_method' => 'percentage', 'cost_rate' => null,
            'spread_type' => 'percentage', 'spread_value' => '0.02',
        ]))->json('gross_profit.amount');

        expect($perUnit)->toBe('1000.00')
            ->and($percentage)->toBe('514.80')
            ->and($perUnit)->not->toBe($percentage);
    });

    it('rejects an amount that is not a plain decimal', function (string $amount): void {
        $this->actingAs($this->operator)
            ->postJson('/exchange/preview', dealPayload(['received_amount' => $amount]))
            ->assertStatus(422);
    })->with(['1e5', '1,000', 'abc', '1.2.3']);

    it('refuses an exchange between one currency and itself', function (): void {
        $this->actingAs($this->operator)
            ->postJson('/exchange/preview', dealPayload(['delivered_currency_id' => $this->egp->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('delivered_currency_id');
    });
});

describe('recording', function (): void {
    it('records the deal and moves both currencies', function (): void {
        $this->actingAs($this->operator)
            ->post('/exchange', dealPayload())
            ->assertRedirect('/exchange')
            ->assertSessionHas('success');

        $resolver = app(LedgerAccountResolver::class);

        $cashEgp = LedgerBalance::query()
            ->where('ledger_account_id', $resolver->forAccount($this->egpSafe, $this->egp)->id)
            ->sole();

        expect($cashEgp->confirmed()->toDisplayString())->toBe('2574000.00')
            ->and(Transaction::query()->sole()->profit_status)->toBe(ProfitStatus::Finalised);
    });

    it('recognises the margin', function (): void {
        $this->actingAs($this->operator)->post('/exchange', dealPayload());

        $profit = LedgerBalance::query()
            ->where('ledger_account_id', app(LedgerAccountResolver::class)
                ->system(LedgerAccountSubkind::TradingProfit, $this->egp)->id)
            ->sole();

        expect($profit->confirmed()->toDisplayString())->toBe('14000.00');
    });

    it('leaves the ledger verifiable', function (): void {
        $this->actingAs($this->operator)->post('/exchange', dealPayload());

        $this->artisan('ledger:verify --transactions')->assertExitCode(0);
    });
});

// Section 3 requires a warning before saving an unexpected loss. Enforced on the
// server, because a warning that only lives in the interface can be skipped by
// anything that does not use it.
describe('the loss guard', function (): void {
    it('refuses a losing deal that has not been confirmed', function (): void {
        $this->actingAs($this->operator)
            ->post('/exchange', dealPayload(['cost_rate' => '52.00']))
            ->assertSessionHasErrors('confirm_loss');

        expect(Transaction::query()->count())->toBe(0);
    });

    it('records a losing deal once it is confirmed', function (): void {
        $this->actingAs($this->operator)
            ->post('/exchange', dealPayload(['cost_rate' => '52.00', 'confirm_loss' => true]))
            ->assertSessionHasNoErrors();

        expect(Transaction::query()->sole()->net_profit)->toBe('-26000.0000000000');
    });

    // A profitable deal must not ask for a confirmation nobody needs to give.
    it('does not ask for confirmation on a profitable deal', function (): void {
        $this->actingAs($this->operator)
            ->post('/exchange', dealPayload())
            ->assertSessionHasNoErrors();
    });

    // A deal profitable on the rate but pushed negative by costs is still a loss.
    it('catches a loss caused by costs rather than the rate', function (): void {
        $this->actingAs($this->operator)
            ->post('/exchange', dealPayload(['expenses' => '20000']))
            ->assertSessionHasErrors('confirm_loss');
    });
});

describe('duplicate submission', function (): void {
    it('records once for a repeated idempotency key', function (): void {
        $payload = dealPayload(['idempotency_key' => 'deal-1']);

        $this->actingAs($this->operator)->post('/exchange', $payload);
        $this->actingAs($this->operator)->post('/exchange', $payload);

        expect(Transaction::query()->count())->toBe(1);
    });
});
