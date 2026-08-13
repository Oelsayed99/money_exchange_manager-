<?php

declare(strict_types=1);

namespace App\Casts;

use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\CurrencySpec;
use App\Domain\Money\Decimal;
use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Domain\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a DECIMAL column to a Money, using a sibling column for the currency.
 *
 *     protected function casts(): array
 *     {
 *         return ['opening_balance' => MoneyCast::class.':currency_id'];
 *     }
 *
 * An amount is meaningless without its currency, so the two always travel together:
 * reading a monetary column yields a Money that already knows its own precision, and
 * writing a Money whose currency contradicts the row is refused.
 *
 * @implements CastsAttributes<Money|null, Money|string|int|null>
 */
final class MoneyCast implements CastsAttributes
{
    public function __construct(private readonly string $currencyColumn = 'currency_id') {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        $spec = $this->resolveSpec($attributes);

        if ($spec === null) {
            throw new InvalidArgumentException(
                "Cannot read [{$key}] as money: column [{$this->currencyColumn}] is missing from the "
                .'loaded attributes, so the amount has no currency. Select it alongside the amount.'
            );
        }

        return Money::of((string) $value, $spec);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        $spec = $this->resolveSpec($attributes);

        if ($value instanceof Money) {
            // Eloquent casts extra pivot attributes in isolation during attach(), before
            // the foreign keys are merged in, so the currency genuinely is not knowable
            // here. Refusing beats storing an amount whose currency was never checked.
            if ($spec === null) {
                throw new InvalidArgumentException(
                    "Cannot verify the currency of [{$key}]: [{$this->currencyColumn}] is not among the "
                    .'attributes being written. Set the amount on the pivot model itself, or pass a plain '
                    .'decimal string, which carries no currency to contradict.'
                );
            }

            // Writing AED into a row whose currency column says USD would produce a
            // number that silently means something other than what it says.
            if (! $value->currency->is($spec)) {
                throw CurrencyMismatch::between($value->currency, $spec, 'store');
            }

            return [$key => $value->toStorageString()];
        }

        $decimal = (string) $value;

        // A bare decimal asserts no currency, so there is nothing for the row to
        // contradict; it is simply normalised to the storage scale.
        Decimal::assertValid($decimal);

        if (Decimal::scaleOf($decimal) > Money::SCALE) {
            throw new InvalidArgumentException(
                "Amount [{$decimal}] for [{$key}] carries more than ".Money::SCALE.' decimal places, '
                .'which cannot be represented without discarding digits.'
            );
        }

        return [$key => Decimal::padTo($decimal, Money::SCALE)];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveSpec(array $attributes): ?CurrencySpec
    {
        $currencyId = $attributes[$this->currencyColumn] ?? null;

        if (is_int($currencyId) || (is_string($currencyId) && ctype_digit($currencyId))) {
            return app(CurrencyRegistry::class)->byId((int) $currencyId);
        }

        return null;
    }
}
