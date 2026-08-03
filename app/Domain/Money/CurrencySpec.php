<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * The precision and rounding policy of a single currency.
 *
 * Deliberately a plain immutable object rather than the Eloquent model: Money is a
 * domain value type and must be constructible and testable without a database. The
 * Currency model produces one of these via Currency::spec().
 *
 * Section 3 requires precision to be defined independently per currency, which is why
 * decimalPlaces travels with the currency rather than being a global constant.
 */
final readonly class CurrencySpec
{
    /** Cannot display more precision than Money stores. */
    public const int MAX_DECIMAL_PLACES = Money::SCALE;

    public function __construct(
        public string $code,
        public int $decimalPlaces = 2,
        public RoundingMode $roundingMode = RoundingMode::HalfUp,
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
