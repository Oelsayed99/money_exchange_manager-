<?php

declare(strict_types=1);

use App\Domain\Money\CurrencyRegistry;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();

    $this->usd = Currency::query()->where('code', 'USD')->sole();
    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->eur = Currency::query()->where('code', 'EUR')->sole();

    $this->operator = User::factory()->create();
    $this->operator->assignRole(Role::Operator->value);
});

function solve(array $payload): TestResponse
{
    return test()->actingAs(test()->operator)->postJson('/exchange/convert', $payload);
}

describe('authorization', function (): void {
    it('requires authentication', function (): void {
        $this->postJson('/exchange/convert', [])->assertUnauthorized();
    });

    it('refuses a viewer', function (): void {
        $viewer = User::factory()->create();
        $viewer->assignRole(Role::Viewer->value);

        $this->actingAs($viewer)->postJson('/exchange/convert', [])->assertForbidden();
    });

    // Working out what a deal comes to is preparing one, not committing it — the same
    // line the profit preview draws.
    it('allows someone who may record but not post', function (): void {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::RecordTransactions->value);

        $this->actingAs($user)->postJson('/exchange/convert', [
            'base_currency_id' => $this->usd->id,
            'quote_currency_id' => $this->egp->id,
            'rate' => '51.48',
            'base_amount' => '50000',
        ])->assertOk();
    });
});

// "I want 100k USD from someone, I will pay him in AED, the rate is 3.67."
describe('solving for the amount you owe', function (): void {
    it('multiplies out the other side and reports it exact', function (): void {
        solve([
            'base_currency_id' => $this->usd->id,
            'quote_currency_id' => $this->egp->id,
            'rate' => '51.48',
            'base_amount' => '50000',
        ])
            ->assertOk()
            ->assertJsonPath('solved_for', 'quote_amount')
            ->assertJsonPath('quote_amount.amount', '2574000.00')
            ->assertJsonPath('quote_amount.currency', 'EGP')
            ->assertJsonPath('exact', true);
    });

    it('solves the base when the quote side is the one you know', function (): void {
        solve([
            'base_currency_id' => $this->usd->id,
            'quote_currency_id' => $this->egp->id,
            'rate' => '51.48',
            'quote_amount' => '2574000',
        ])
            ->assertOk()
            ->assertJsonPath('solved_for', 'base_amount')
            ->assertJsonPath('base_amount.amount', '50000.00')
            ->assertJsonPath('exact', true);
    });

    // "Someone wants to buy EGP from me and pay in EUR at 54.20." A million pounds does
    // not divide into euros evenly, and the operator has to be told before quoting it.
    it('says when the figure had to be cut', function (): void {
        solve([
            'base_currency_id' => $this->eur->id,
            'quote_currency_id' => $this->egp->id,
            'rate' => '54.20',
            'quote_amount' => '1000000',
        ])
            ->assertOk()
            ->assertJsonPath('base_amount.amount', '18450.184501845')
            ->assertJsonPath('exact', false);
    });
});

describe('solving for the rate', function (): void {
    it('derives the rate the two amounts imply', function (): void {
        solve([
            'base_currency_id' => $this->usd->id,
            'quote_currency_id' => $this->egp->id,
            'base_amount' => '50000',
            'quote_amount' => '2574000',
        ])
            ->assertOk()
            ->assertJsonPath('solved_for', 'rate')
            ->assertJsonPath('rate', '51.480000000000')
            ->assertJsonPath('exact', true);
    });

    // The point of deriving it: the operator overwrites a computed amount with the
    // figure actually settled, and the rate follows the money rather than the money
    // following a rate nobody honoured.
    it('follows the amount when the operator overrides it', function (): void {
        solve([
            'base_currency_id' => $this->eur->id,
            'quote_currency_id' => $this->egp->id,
            'base_amount' => '18450',
            'quote_amount' => '1000000',
        ])
            ->assertOk()
            ->assertJsonPath('rate', '54.200542005420')
            ->assertJsonPath('exact', false);
    });
});

describe('what it will not do', function (): void {
    it('refuses to guess when only one quantity is given', function (): void {
        solve([
            'base_currency_id' => $this->usd->id,
            'quote_currency_id' => $this->egp->id,
            'rate' => '51.48',
        ])->assertJsonValidationErrors('rate');
    });

    // Three values with two degrees of freedom: accepting all three would let the
    // caller assert a rate the amounts contradict.
    it('refuses all three at once', function (): void {
        solve([
            'base_currency_id' => $this->usd->id,
            'quote_currency_id' => $this->egp->id,
            'rate' => '51.48',
            'base_amount' => '50000',
            'quote_amount' => '2574000',
        ])->assertJsonValidationErrors('rate');
    });

    it('refuses a rate that is not positive', function (string $rate): void {
        solve([
            'base_currency_id' => $this->usd->id,
            'quote_currency_id' => $this->egp->id,
            'rate' => $rate,
            'base_amount' => '50000',
        ])->assertJsonValidationErrors('rate');
    })->with(['0', '-51.48']);

    it('refuses to recover a rate from a zero amount', function (): void {
        solve([
            'base_currency_id' => $this->usd->id,
            'quote_currency_id' => $this->egp->id,
            'base_amount' => '0',
            'quote_amount' => '2574000',
        ])->assertJsonValidationErrors('base_amount');
    });

    it('refuses one currency against itself', function (): void {
        solve([
            'base_currency_id' => $this->usd->id,
            'quote_currency_id' => $this->usd->id,
            'rate' => '1',
            'base_amount' => '50000',
        ])->assertJsonValidationErrors('quote_currency_id');
    });

    it('refuses anything that is not a plain decimal', function (string $amount): void {
        solve([
            'base_currency_id' => $this->usd->id,
            'quote_currency_id' => $this->egp->id,
            'rate' => '51.48',
            'base_amount' => $amount,
        ])->assertJsonValidationErrors('base_amount');
    })->with(['1e5', '1,000', 'abc', '1.2.3']);

    it('refuses a rate carrying more precision than a rate can hold', function (): void {
        solve([
            'base_currency_id' => $this->usd->id,
            'quote_currency_id' => $this->egp->id,
            'rate' => '51.4812345678901',
            'base_amount' => '50000',
        ])->assertJsonValidationErrors('rate');
    });

    it('records nothing', function (): void {
        solve([
            'base_currency_id' => $this->usd->id,
            'quote_currency_id' => $this->egp->id,
            'rate' => '51.48',
            'base_amount' => '50000',
        ])->assertOk();

        expect(Transaction::query()->count())->toBe(0);
    });
});

// Risk R1 at yet another boundary. A JSON number is a float64 in the browser, and
// 2,574,000.00 is exactly the kind of figure this application exists to keep intact.
it('sends every amount as a string', function (): void {
    $json = solve([
        'base_currency_id' => $this->usd->id,
        'quote_currency_id' => $this->egp->id,
        'rate' => '51.48',
        'base_amount' => '50000',
    ])->content();

    expect($json)->toContain('"amount":"2574000.00"')
        ->and($json)->not->toContain('"amount":2574000')
        ->and($json)->toContain('"rate":"51.480000000000"');
});
