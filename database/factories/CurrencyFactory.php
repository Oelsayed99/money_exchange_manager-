<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Currency>
 */
final class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'name' => $this->faker->words(2, true),
            'name_ar' => null,
            'symbol' => '¤',
            'decimal_places' => 2,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function withDecimalPlaces(int $places): self
    {
        return $this->state(fn (): array => ['decimal_places' => $places]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
