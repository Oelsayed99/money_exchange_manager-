<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Currency;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->user = userWithRole(Role::Owner);
});

describe('access', function (): void {
    it('requires authentication', function (string $method, string $uri): void {
        $this->{$method}($uri)->assertRedirect('/login');
    })->with([
        ['get', '/currencies'],
        ['get', '/currencies/create'],
        ['post', '/currencies'],
    ]);

    // Currencies are referenced by ledger history that must stay reproducible
    // (Section 7), so there is deliberately no delete route. The URI itself exists for
    // PUT and PATCH, so DELETE is rejected as method-not-allowed rather than not-found.
    it('exposes no destroy route', function (): void {
        $currency = Currency::factory()->create();

        expect(Route::has('currencies.destroy'))->toBeFalse();

        $this->actingAs($this->user)
            ->delete("/currencies/{$currency->id}")
            ->assertStatus(405);

        expect(Currency::query()->whereKey($currency->id)->exists())->toBeTrue();
    });
});

describe('index', function (): void {
    it('lists currencies ordered by sort order', function (): void {
        $this->seed(CurrencySeeder::class);

        $this->actingAs($this->user)
            ->get('/currencies')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('currencies/index')
                ->has('currencies', 4)
                ->where('currencies.0.code', 'USD')
                ->where('currencies.3.code', 'EGP')
            );
    });

    it('renders an empty state without error', function (): void {
        $this->actingAs($this->user)
            ->get('/currencies')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('currencies', 0));
    });

    // Risk R1: JavaScript's number is float64, so a monetary amount must never reach
    // the client as a JSON number. This asserts the actual page payload, not the type.
    it('sends the sample amount as a string, never a JSON number', function (): void {
        Currency::factory()->create(['code' => 'USD', 'decimal_places' => 2]);

        $response = $this->actingAs($this->user)->get('/currencies');

        $props = $response->viewData('page')['props'];
        $sample = $props['currencies'][0]['sample'];

        expect($sample['amount'])->toBeString()
            ->and($sample['amount'])->not->toBeFloat()
            ->and($sample)->toBe(['amount' => '1234.50', 'currency' => 'USD']);

        // And once encoded, the amount is still quoted rather than numeric.
        expect(json_encode($sample))->toContain('"amount":"1234.50"');
    });

    // Padded up to the currency's precision, never cut down to it: JPY declares zero
    // decimals yet still shows the .5, because nothing here rounds.
    it('renders the sample at each currency declared precision without rounding', function (): void {
        Currency::factory()->create(['code' => 'JPY', 'decimal_places' => 0, 'sort_order' => 1]);
        Currency::factory()->create(['code' => 'KWD', 'decimal_places' => 3, 'sort_order' => 2]);

        $props = $this->actingAs($this->user)->get('/currencies')->viewData('page')['props'];

        expect($props['currencies'][0]['sample']['amount'])->toBe('1234.5')
            ->and($props['currencies'][1]['sample']['amount'])->toBe('1234.500');
    });
});

describe('create', function (): void {
    it('renders an empty create form', function (): void {
        $this->actingAs($this->user)
            ->get('/currencies/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('currencies/form')
                ->where('currency', null)
            );
    });

    it('stores a currency', function (): void {
        $this->actingAs($this->user)
            ->post('/currencies', [
                'code' => 'KWD',
                'name' => 'Kuwaiti Dinar',
                'name_ar' => 'دينار كويتي',
                'symbol' => 'د.ك',
                'decimal_places' => 3,
                'is_active' => true,
                'sort_order' => 50,
            ])
            ->assertRedirect('/currencies')
            ->assertSessionHas('success');

        $currency = Currency::query()->where('code', 'KWD')->firstOrFail();

        expect($currency->decimal_places)->toBe(3)
            ->and($currency->name_ar)->toBe('دينار كويتي');
    });

    it('uppercases the code before storing', function (): void {
        $this->actingAs($this->user)->post('/currencies', currencyPayload(['code' => 'kwd']));

        expect(Currency::query()->where('code', 'KWD')->exists())->toBeTrue();
    });

    // Without normalising before validation, 'usd' would pass the uniqueness check
    // against an existing 'USD' and then collide at the database instead.
    it('rejects a duplicate code regardless of case', function (): void {
        Currency::factory()->create(['code' => 'USD']);

        $this->actingAs($this->user)
            ->post('/currencies', currencyPayload(['code' => 'usd']))
            ->assertSessionHasErrors('code');

        expect(Currency::query()->count())->toBe(1);
    });

    it('rejects precision beyond the storage scale', function (): void {
        $this->actingAs($this->user)
            ->post('/currencies', currencyPayload(['decimal_places' => 11]))
            ->assertSessionHasErrors('decimal_places');
    });

    it('rejects a code containing digits', function (): void {
        $this->actingAs($this->user)
            ->post('/currencies', currencyPayload(['code' => 'US1']))
            ->assertSessionHasErrors('code');
    });

    it('requires the mandatory fields', function (): void {
        $this->actingAs($this->user)
            ->post('/currencies', [])
            ->assertSessionHasErrors(['code', 'name', 'decimal_places', 'sort_order']);
    });
});

describe('update', function (): void {
    it('renders the edit form with the currency', function (): void {
        $currency = Currency::factory()->create(['code' => 'AED']);

        $this->actingAs($this->user)
            ->get("/currencies/{$currency->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('currencies/form')
                ->where('currency.code', 'AED')
            );
    });

    it('updates a currency', function (): void {
        $currency = Currency::factory()->create(['code' => 'AED', 'decimal_places' => 2]);

        $this->actingAs($this->user)
            ->put("/currencies/{$currency->id}", currencyPayload([
                'code' => 'AED',
                'name' => 'UAE Dirham',
                'decimal_places' => 3,
            ]))
            ->assertRedirect('/currencies')
            ->assertSessionHas('success');

        expect($currency->fresh()?->decimal_places)->toBe(3);
    });

    it('allows a currency to keep its own code', function (): void {
        $currency = Currency::factory()->create(['code' => 'AED']);

        $this->actingAs($this->user)
            ->put("/currencies/{$currency->id}", currencyPayload(['code' => 'AED']))
            ->assertSessionHasNoErrors();
    });

    it('still rejects a code belonging to another currency', function (): void {
        Currency::factory()->create(['code' => 'USD']);
        $currency = Currency::factory()->create(['code' => 'AED']);

        $this->actingAs($this->user)
            ->put("/currencies/{$currency->id}", currencyPayload(['code' => 'USD']))
            ->assertSessionHasErrors('code');
    });

    it('can deactivate a currency', function (): void {
        $currency = Currency::factory()->create(['code' => 'AED', 'is_active' => true]);

        $this->actingAs($this->user)
            ->put("/currencies/{$currency->id}", currencyPayload(['code' => 'AED', 'is_active' => false]));

        expect($currency->fresh()?->is_active)->toBeFalse();
    });
});

/** @param array<string, mixed> $overrides */
function currencyPayload(array $overrides = []): array
{
    return [
        'code' => 'KWD',
        'name' => 'Kuwaiti Dinar',
        'name_ar' => null,
        'symbol' => 'د.ك',
        'decimal_places' => 2,
        'is_active' => true,
        'sort_order' => 0,
        ...$overrides,
    ];
}
