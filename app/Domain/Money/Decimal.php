<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic over bcmath.
 *
 * Every monetary value in this system is a decimal string. Binary floating point is
 * never used for money, per Section 3: in IEEE-754, `0.1 + 0.2 !== 0.3`, and a ledger
 * cannot absorb that class of error.
 *
 * **Nothing in this system rounds.** There is no rounding mode, no half-up, no
 * banker's rounding, and no per-currency rounding policy. An amount is never nudged
 * up, and never nudged to a nearest value.
 *
 * The one thing that is unavoidable is *truncation*, and only for division: 10 ÷ 3
 * does not terminate, so a finite representation has to stop somewhere. Truncation is
 * not rounding — it only ever drops digits toward zero, so a value can never grow, and
 * it happens at the tenth decimal place, far below the significance of any currency.
 *
 * bcmath already truncates rather than rounds, which is exactly the behaviour wanted
 * here, so the bc* scale argument is used directly.
 */
final class Decimal
{
    /**
     * Scale for intermediate results. Sits well above any storage scale so that
     * products keep full precision through a calculation.
     */
    public const int WORKING_SCALE = 24;

    /**
     * Plain decimal only: no scientific notation, no separators, no whitespace.
     *
     * Anchored with \z rather than $ deliberately. PHP's $ also matches immediately
     * before a trailing newline, so "1.00\n" — exactly what a CSV or file import
     * hands you — would otherwise pass validation.
     */
    private const string PATTERN = '/^-?\d+(\.\d+)?\z/';

    /** @phpstan-assert-if-true numeric-string $value */
    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * Narrows the value to numeric-string for static analysis. The regex is stricter
     * than PHPStan's numeric-string (which would admit scientific notation), so the
     * assertion is sound in the direction that matters.
     *
     * @phpstan-assert numeric-string $value
     */
    public static function assertValid(string $value): void
    {
        if (! self::isValid($value)) {
            throw new InvalidArgumentException(
                "Not a valid decimal string: [{$value}]. Scientific notation, "
                .'thousands separators, whitespace and empty values are rejected.'
            );
        }
    }

    public static function scaleOf(string $value): int
    {
        $dot = strpos($value, '.');

        return $dot === false ? 0 : strlen($value) - $dot - 1;
    }

    /**
     * Pad a value out to at least the given scale, without ever losing a digit.
     *
     * A value already carrying more decimals than requested is returned unchanged.
     * This is the only formatting operation used for display, which is why display
     * can never alter an amount.
     *
     * @return numeric-string
     */
    public static function padTo(string $value, int $scale): string
    {
        self::assertValid($value);
        self::assertScale($scale);

        if (self::scaleOf($value) >= $scale) {
            return self::normaliseZero($value);
        }

        return self::normaliseZero(bcadd($value, '0', $scale));
    }

    /**
     * Drop digits beyond the given scale, toward zero.
     *
     * This is truncation, not rounding: 0.999 at scale 2 is 0.99, and -0.999 at scale
     * 2 is -0.99. Magnitude never increases. Reserved for division, where a finite
     * representation is mathematically impossible to guarantee.
     *
     * @return numeric-string
     */
    public static function truncateTo(string $value, int $scale): string
    {
        self::assertValid($value);
        self::assertScale($scale);

        return self::normaliseZero(bcadd($value, '0', $scale));
    }

    /**
     * Whether reducing this value to the given scale would discard a non-zero digit.
     *
     * Lets callers refuse to lose precision rather than silently accept truncation.
     *
     * @param  numeric-string  $value
     */
    public static function losesPrecisionAt(string $value, int $scale): bool
    {
        return bccomp($value, bcadd($value, '0', $scale), self::WORKING_SCALE) !== 0;
    }

    /**
     * The same number, without the zeros a fixed-scale column pads it with.
     *
     * A rate is stored in a `decimal(_, 12)` and comes back as '50.850000000000'. That
     * is the same number the operator typed, but nobody typed it and nobody wants to
     * read it on a statement. Dropping trailing zeros after the point changes nothing
     * about the value.
     *
     * Presentation only. Nothing arithmetic depends on how wide a decimal is written,
     * and storage keeps whatever scale its column declares.
     */
    public static function trimTrailingZeros(string $value): string
    {
        // Validated rather than assumed: this is fed straight from a database column.
        self::assertValid($value);

        if (! str_contains($value, '.')) {
            return $value;
        }

        // A valid decimal always carries a digit before the point, so trimming the
        // fraction away can never leave nothing behind.
        return rtrim(rtrim($value, '0'), '.');
    }

    /**
     * bcmath can produce a signed zero such as '-0.00'. A negative zero must never
     * reach storage, a report, or a customer-facing statement.
     *
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private static function normaliseZero(string $value): string
    {
        return bccomp($value, '0', self::scaleOf($value)) === 0
            ? ltrim($value, '-')
            : $value;
    }

    private static function assertScale(int $scale): void
    {
        if ($scale < 0) {
            throw new InvalidArgumentException("Scale must not be negative, got [{$scale}].");
        }
    }
}
