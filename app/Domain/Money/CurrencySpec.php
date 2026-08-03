<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * The display precision of a single currency.
 *
 * Deliberately a plain immutable object rather than the Eloquent model: Money is a
 * domain value type and must be constructible and testable without a database. The
 * Currency model produces one of these via Currency::spec().
 *
 * decimalPlaces is a *minimum* for display, never a rounding instruction. A USD amount
 * of 1000 is shown as 1000.00; a USD amount of 1000.123456 is shown in full. Nothing
 * in this system rounds an amount to a currency's precision.
 */
final readonly class CurrencySpec
{
    /** Cannot display more precision than Money stores. */
    public const int MAX_DECIMAL_PLACES = Money::SCALE;

    public function __construct(
        public string $code,
        public int $decimalPlaces = 2,
    ) {
        if (trim($code) === '') {
            throw new InvalidArgumentException('Currency code must not be empty.');
        }

        if ($code !== strtoupper($code)) {
            throw new InvalidArgumentException("Currency code must be uppercase, got [{$code}].");
        }

        if ($decimalPlaces < 0 || $decimalPlaces > self::MAX_DECIMAL_PLACES) {
            throw new InvalidArgumentException(
                "Currency [{$code}] declares {$decimalPlaces} decimal places; "
                .'must be between 0 and '.self::MAX_DECIMAL_PLACES.'.'
            );
        }
    }

    public function is(self $other): bool
    {
        return $this->code === $other->code;
    }
}
