<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Money\RoundingMode;
use App\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * The four currencies named in Section 1.
 *
 * This is a starting set, not a fixed list — administrators add currencies through the
 * application, and nothing here needs editing for them to do so.
 *
 * All four use 2 decimal places, which matches ISO 4217 for USD, EUR, AED and EGP.
 * Currencies with other exponents (KWD at 3, JPY at 0) are supported by the schema and
 * by Money; none simply happen to be in the initial set.
 */
final class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            ['code' => 'USD', 'name' => 'US Dollar', 'name_ar' => 'دولار أمريكي', 'symbol' => '$', 'sort_order' => 10],
            ['code' => 'EUR', 'name' => 'Euro', 'name_ar' => 'يورو', 'symbol' => '€', 'sort_order' => 20],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'name_ar' => 'درهم إماراتي', 'symbol' => 'د.إ', 'sort_order' => 30],
            ['code' => 'EGP', 'name' => 'Egyptian Pound', 'name_ar' => 'جنيه مصري', 'symbol' => 'ج.م', 'sort_order' => 40],
        ];

        foreach ($currencies as $currency) {
            // Idempotent: seeding an existing database must not duplicate currencies
            // or reset an administrator's precision and rounding choices.
            Currency::query()->updateOrCreate(
                ['code' => $currency['code']],
                [
                    ...$currency,
                    'decimal_places' => 2,
                    'rounding_mode' => RoundingMode::HalfUp,
                    'is_active' => true,
                ],
            );
        }
    }
}
