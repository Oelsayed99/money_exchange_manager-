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
    $this->operator->assignRole(Role::Owner->value);

    $this->stranger = User::factory()->create();
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

/*
 * A margin method with nothing to work from.
 *
 * cost_rate and profit_value are optional in the rules, because which one is needed
 * depends on the method — and nothing was asking the method. Choosing "rate difference"
 * and leaving the cost rate empty passed validation and threw a DomainException out of
 * the calculator: a stack trace on the screen where the day's money is recorded.
 */
describe('the margin method has to have what it needs', function (): void {
    it('refuses a rate-difference deal with no cost rate', function (): void {
        $this->actingAs($this->operator)
            ->post('/exchange', dealPayload(['cost_rate' => null]))
            ->assertSessionHasErrors('cost_rate');

        expect(Transaction::query()->count())->toBe(0);
    });

    it('refuses a cost rate of zero, which is a missing figure and not a cheap deal', function (): void {
        $this->actingAs($this->operator)
            ->post('/exchange', dealPayload(['cost_rate' => '0']))
            ->assertSessionHasErrors('cost_rate');
    });

    it('refuses each stated method with no figure beside it', function (string $method): void {
        $this->actingAs($this->operator)
            ->post('/exchange', dealPayload([
                'profit_method' => $method,
                'cost_rate' => null,
                'profit_value' => null,
            ]))
            ->assertSessionHasErrors('profit_value');
    })->with(['per_unit', 'percentage', 'fixed_amount', 'manual']);

    it('asks for nothing when there is no margin to work out', function (): void {
        $this->actingAs($this->operator)
            ->post('/exchange', dealPayload([
                'profit_method' => 'none',
                'cost_rate' => null,
                'profit_value' => null,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    });

    // The third way the calculator could be reached with nothing to work from: a leg
    // of zero has no rate, so it throws there too.
    it('refuses a leg of nothing', function (string $field): void {
        $this->actingAs($this->operator)
            ->post('/exchange', dealPayload([$field => '0']))
            ->assertSessionHasErrors($field);

        expect(Transaction::query()->count())->toBe(0);
    })->with(['received_amount', 'delivered_amount']);

    it('refuses a negative leg, which is the same deal the other way round', function (): void {
        $this->actingAs($this->operator)
            ->post('/exchange', dealPayload(['received_amount' => '-2574000']))
            ->assertSessionHasErrors('received_amount');
    });

    it('refuses a negative fee, expense or commission', function (string $field): void {
        $this->actingAs($this->operator)
            ->post('/exchange', dealPayload([$field => '-100']))
            ->assertSessionHasErrors($field);
    })->with(['fees_charged', 'expenses', 'commissions']);

    // Every message names the field the way the screen does. Deriving the label from
    // the field name printed "transactions.exchange.fees_charged" at somebody.
    it('names the field the way the screen does', function (): void {
        $errors = $this->actingAs($this->operator)
            ->post('/exchange', dealPayload(['fees_charged' => '-100']))
            ->assertSessionHasErrors('fees_charged')
            ->getSession()->get('errors');

        expect($errors->first('fees_charged'))->toBe('Fees charged cannot be negative.');
    });

    // The preview shares the request, so it had the same hole — and it is the one an
    // operator hits first, while they are still typing.
    it('answers the preview with a field error rather than failing', function (): void {
        $this->actingAs($this->operator)
            ->postJson('/exchange/preview', dealPayload(['cost_rate' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cost_rate');
    });
});

describe('authorization', function (): void {
    it('requires authentication', function (): void {
        $this->get('/exchange')->assertRedirect('/login');
    });

    it('refuses somebody holding no role', function (): void {
        $this->actingAs($this->stranger)->get('/exchange')->assertForbidden();
        $this->actingAs($this->stranger)->post('/exchange', dealPayload())->assertForbidden();
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
                ->has('profitMethods', 6)
                ->has('methods', 5)
            );
    });

    // Section 3: a margin is never a bare number, and the operator is asked once.
    //
    // Both readings of 0.02 are methods in the one list. There is no second question
    // about what the first answer meant, and no "flat amount" spread duplicating the
    // fixed-amount method.
    it('offers every way of stating a margin in one list', function (): void {
        $props = $this->actingAs($this->operator)->get('/exchange')->viewData('page')['props'];

        expect(collect($props['profitMethods'])->pluck('value')->all())
            ->toBe(['rate_difference', 'per_unit', 'percentage', 'fixed_amount', 'manual', 'none'])
            ->and($props)->not->toHaveKey('spreadTypes');
    });

    // Each method says what its own figure is called, so the box is never labelled
    // with a word that means something different depending on the method above it.
    it('names the figure each method asks for', function (): void {
        $props = $this->actingAs($this->operator)->get('/exchange')->viewData('page')['props'];
        $methods = collect($props['profitMethods'])->keyBy('value');

        expect($methods['per_unit']['valueLabel'])->not->toBe($methods['percentage']['valueLabel'])
            ->and($methods['rate_difference']['needsValue'])->toBeFalse()
            ->and($methods['per_unit']['needsValue'])->toBeTrue()
            ->and($methods['none']['needsValue'])->toBeFalse();
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

    // The same number, two meanings, two answers — two methods, since the meaning is
    // the method rather than a follow-up question about it.
    it('distinguishes a per-unit margin from a percentage', function (): void {
        $perUnit = $this->actingAs($this->operator)->postJson('/exchange/preview', dealPayload([
            'profit_method' => 'per_unit', 'cost_rate' => null, 'profit_value' => '0.02',
        ]))->json('gross_profit.amount');

        $percentage = $this->actingAs($this->operator)->postJson('/exchange/preview', dealPayload([
            'profit_method' => 'percentage', 'cost_rate' => null, 'profit_value' => '0.02',
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
