<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BalanceBucket;
use App\Enums\CounterpartyType;
use App\Models\Counterparty;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Counterparty>
 */
final class CounterpartyFactory extends Factory
{
    protected $model = Counterparty::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'type' => CounterpartyType::Customer,
            'phone' => $this->faker->numerify('+9715########'),
            'email' => $this->faker->unique()->safeEmail(),
            'country' => 'AE',
            'preferred_currency_id' => null,
            'is_active' => true,
        ];
    }

    public function ofType(CounterpartyType $type): self
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * Declare opening positions.
     *
     * @param  array<string, array<string, string>>  $positions  bucket value => (currency code => amount)
     */
    public function withPositions(array $positions): self
    {
        return $this->afterCreating(function (Counterparty $counterparty) use ($positions): void {
            foreach ($positions as $bucket => $amounts) {
                foreach ($amounts as $code => $amount) {
                    $currency = Currency::query()->where('code', strtoupper($code))->firstOrFail();

                    $counterparty->setOpeningBalance(
                        BalanceBucket::from($bucket),
                        $currency,
                        $currency->money($amount),
                    );
                }
            }
        });
    }
}
