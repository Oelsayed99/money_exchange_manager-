<?php

declare(strict_types=1);

use App\Domain\Money\CurrencySpec;
use App\Models\Currency;
use Database\Seeders\CurrencySeeder;
use Illuminate\Database\QueryException;

describe('schema', function (): void {
    it('normalises the code to uppercase so one currency cannot become two', function (): void {
        $currency = Currency::factory()->create(['code' => 'usd']);

        expect($currency->fresh()?->code)->toBe('USD');
    });

    it('trims whitespace from the code', function (): void {
        expect(Currency::factory()->create(['code' => '  eur '])->fresh()?->code)->toBe('EUR');
    });

    it('rejects a duplicate code', function (): void {
        Currency::factory()->create(['code' => 'USD']);

        expect(fn () => Currency::factory()->create(['code' => 'USD']))
            ->toThrow(QueryException::class);
    });

    // Section 19, enforced at the database rather than only in PHP: precision beyond
    // Money's storage scale would be unrepresentable and silently lost.
    it('rejects precision beyond the storage scale at the database level', function (): void {
        expect(fn () => Currency::factory()->create(['decimal_places' => 11]))
            ->toThrow(QueryException::class);
    });

    it('accepts precision at the boundary', function (): void {
        expect(Currency::factory()->create(['decimal_places' => 10])->fresh()?->decimal_places)->toBe(10);
    });

    it('accepts a zero-decimal currency', function (): void {
        expect(Currency::factory()->create(['decimal_places' => 0])->fresh()?->decimal_places)->toBe(0);
    });
});

describe('casts', function (): void {

    it('casts flags and integers', function (): void {
        $currency = Currency::factory()->inactive()->create(['sort_order' => 7])->fresh();

        expect($currency?->is_active)->toBeFalse()
            ->and($currency?->sort_order)->toBe(7)
            ->and($currency?->decimal_places)->toBeInt();
    });
});

describe('domain bridge', function (): void {
    it('produces an immutable spec for the domain layer', function (): void {
        $currency = Currency::factory()->create([
            'code' => 'AED',
            'decimal_places' => 2,
        ]);

        $spec = $currency->spec();

        expect($spec)->toBeInstanceOf(CurrencySpec::class)
            ->and($spec->code)->toBe('AED')
            ->and($spec->decimalPlaces)->toBe(2);
    });

    it('builds money in its own currency', function (): void {
        $currency = Currency::factory()->create(['code' => 'AED', 'decimal_places' => 2]);

        expect($currency->money('3670.5')->toDisplayString())->toBe('3670.50')
            ->and($currency->zero()->isZero())->toBeTrue();
    });

    it('pads to its own precision without ever cutting a digit', function (): void {
        $jpy = Currency::factory()->create(['code' => 'JPY', 'decimal_places' => 0]);
        $kwd = Currency::factory()->create(['code' => 'KWD', 'decimal_places' => 3]);

        expect($jpy->money('1234')->toDisplayString())->toBe('1234')
            ->and($kwd->money('1')->toDisplayString())->toBe('1.000')
            // Nothing rounds: a value more precise than the currency is shown in full.
            ->and($jpy->money('1234.56')->toDisplayString())->toBe('1234.56');
    });
});

describe('seeder', function (): void {
    beforeEach(function (): void {
        $this->seed(CurrencySeeder::class);
    });

    it('seeds the four currencies named in the specification', function (): void {
        expect(Currency::query()->pluck('code')->sort()->values()->all())
            ->toBe(['AED', 'EGP', 'EUR', 'USD']);
    });

    it('seeds them all active with two decimal places', function (): void {
        expect(Currency::query()->where('is_active', true)->count())->toBe(4)
            ->and(Currency::query()->where('decimal_places', 2)->count())->toBe(4);
    });

    it('is idempotent, so re-seeding never duplicates or resets', function (): void {
        Currency::query()->where('code', 'USD')->update(['decimal_places' => 4]);

        $this->seed(CurrencySeeder::class);

        expect(Currency::query()->count())->toBe(4);
    });

    // Risk R8: Arabic must survive the round trip through MySQL. utf8mb4 is set from
    // the first migration precisely because retrofitting it after Arabic data exists
    // is a lossy migration.
    it('stores and retrieves Arabic names without corruption', function (): void {
        $names = Currency::query()->orderBy('sort_order')->pluck('name_ar', 'code')->all();

        expect($names['USD'])->toBe('دولار أمريكي')
            ->and($names['EUR'])->toBe('يورو')
            ->and($names['AED'])->toBe('درهم إماراتي')
            ->and($names['EGP'])->toBe('جنيه مصري');
    });

    it('stores Arabic currency symbols without corruption', function (): void {
        expect(Currency::query()->where('code', 'AED')->value('symbol'))->toBe('د.إ');
    });
});
