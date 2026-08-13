<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
final class AccountFactory extends Factory
{
    protected $model = Account::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company().' account',
            'type' => AccountType::Bank,
            'owner' => $this->faker->name(),
            'provider' => $this->faker->company(),
            'identifier' => (string) $this->faker->numerify('GB##ABCD########'),
            'is_active' => true,
            'color' => '#3b82f6',
            'icon' => 'landmark',
            'sort_order' => 0,
        ];
    }

    public function ofType(AccountType $type): self
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * Attach currencies with declared opening balances.
     *
     * @param  array<string, string|int>  $balances  currency code => opening balance
     */
    public function holding(array $balances): self
    {
        return $this->afterCreating(function (Account $account) use ($balances): void {
            foreach ($balances as $code => $opening) {
                $currency = Currency::query()->where('code', strtoupper($code))->firstOrFail();

                $account->currencies()->attach($currency->id, ['opening_balance' => (string) $opening]);
            }
        });
    }
}
