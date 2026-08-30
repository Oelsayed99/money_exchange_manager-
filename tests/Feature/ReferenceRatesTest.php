<?php

declare(strict_types=1);

use App\Domain\Money\CurrencyRegistry;
use App\Domain\Rates\ExchangeRateApi;
use App\Domain\Rates\RateProvider;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A reference rate is something to look at, not something to calculate with.
 *
 * The dashboard shows where the market is so the person at the counter can price a deal.
 * What the ledger records is the two amounts that actually moved — which is the reason a
 * deal booked in June cannot change value in December. If a live rate ever leaked into
 * that path, the whole guarantee would go with it.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    Cache::flush();

    config()->set('services.rates.enabled', true);
    config()->set('services.rates.base', 'USD');

    $this->user = User::factory()->create();
    $this->user->assignRole(Role::Owner->value);
});

function ratesBody(string $rates = '"USD":1,"EGP":50.252612,"EUR":0.861354,"AED":3.6725'): string
{
    return '{"result":"success","base_code":"USD","time_last_update_utc":"Sat, 29 Aug 2026 00:02:31 +0000","rates":{'.$rates.'}}';
}

describe('reading the feed', function (): void {
    it('quotes each active currency against the base', function (): void {
        Http::fake(['open.er-api.com/*' => Http::response(ratesBody())]);

        $rates = app(RateProvider::class)->latest();

        expect($rates?->base)->toBe('USD')
            ->and($rates?->rates['EGP'])->toBe('50.252612')
            ->and($rates?->rates['AED'])->toBe('3.6725');
    });

    // The reason the body is read as text rather than decoded: a rate that has been
    // through a float is a rate somebody can eventually multiply by.
    it('keeps the provider is own digits, never a float', function (): void {
        Http::fake(['open.er-api.com/*' => Http::response(ratesBody('"USD":1,"EGP":50.2526129999999'))]);

        $rates = app(RateProvider::class)->latest();

        expect($rates?->rates['EGP'])->toBe('50.2526129999999')->toBeString();
    });

    it('leaves the base out of its own list', function (): void {
        Http::fake(['open.er-api.com/*' => Http::response(ratesBody())]);

        $quotes = collect(app(RateProvider::class)->latest()?->forCodes(['USD', 'EGP', 'EUR']) ?? []);

        expect($quotes->pluck('code')->all())->toBe(['EGP', 'EUR']);
    });

    it('asks once and then remembers', function (): void {
        Http::fake(['open.er-api.com/*' => Http::response(ratesBody())]);

        app(RateProvider::class)->latest();
        app(RateProvider::class)->latest();

        Http::assertSentCount(1);
    });

    it('makes no request at all when the feed is switched off', function (): void {
        config()->set('services.rates.enabled', false);
        Http::fake();

        expect(app(RateProvider::class)->latest())->toBeNull();

        Http::assertNothingSent();
    });
});

// Somebody else's outage is not a reason to withhold the ledger.
describe('when the feed cannot be had', function (): void {
    it('returns nothing on a failed response', function (): void {
        Http::fake(['open.er-api.com/*' => Http::response('', 503)]);

        expect(app(RateProvider::class)->latest())->toBeNull();
    });

    it('returns nothing on a body it cannot read', function (): void {
        Http::fake(['open.er-api.com/*' => Http::response('not json at all')]);

        expect(app(RateProvider::class)->latest())->toBeNull();
    });

    it('returns nothing when the provider reports an error', function (): void {
        Http::fake(['open.er-api.com/*' => Http::response('{"result":"error","error-type":"unsupported-code"}')]);

        expect(app(RateProvider::class)->latest())->toBeNull();
    });

    it('still renders the dashboard', function (): void {
        Http::fake(['open.er-api.com/*' => fn () => throw new RuntimeException('network is down')]);

        $props = $this->actingAs($this->user)->get('/dashboard')->assertOk()->viewData('page')['props'];

        expect($props['rates'])->toBeNull()
            ->and($props['dashboard'])->toBeArray();
    });
});

describe('on the dashboard', function (): void {
    it('sends the quotes with the base and when they were published', function (): void {
        Http::fake(['open.er-api.com/*' => Http::response(ratesBody())]);

        $props = $this->actingAs($this->user)->get('/dashboard')->viewData('page')['props'];

        expect($props['rates']['base'])->toBe('USD')
            ->and($props['rates']['updated_at'])->toStartWith('2026-08-29')
            ->and(collect($props['rates']['quotes'])->pluck('code'))->toContain('EGP');
    });

    it('sends every rate as a string, never a JSON number', function (): void {
        Http::fake(['open.er-api.com/*' => Http::response(ratesBody())]);

        $props = $this->actingAs($this->user)->get('/dashboard')->viewData('page')['props'];

        expect(json_encode($props['rates']['quotes']))->toContain('"rate":"50.252612"');
    });
});

/**
 * The line this whole feature sits behind.
 *
 * Structural, because no unit test can prove a negative about code nobody has written
 * yet. If a future change reaches for a live rate from inside the ledger, the exchange
 * or a statement, this fails and says why.
 */
it('is never reachable from anything that writes or reports the ledger', function (): void {
    $sealed = ['app/Domain/Ledger', 'app/Domain/Exchange', 'app/Domain/Statement', 'app/Domain/Reconciliation'];
    $offenders = [];

    foreach ($sealed as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory)));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, 'Domain\\Rates') || str_contains($source, 'RateProvider')) {
                $offenders[] = $directory.'/'.$file->getFilename();
            }
        }
    }

    expect($offenders)->toBe([], 'Reference rates reached the ledger: '.implode(', ', $offenders));
});

it('is not something the exchange calculator could reach even by accident', function (): void {
    $calculator = (string) file_get_contents(base_path('app/Domain/Exchange/ProfitCalculator.php'));

    expect($calculator)->not->toContain('Http::')
        ->not->toContain('config(')
        ->and(class_exists(ExchangeRateApi::class))->toBeTrue();
});
