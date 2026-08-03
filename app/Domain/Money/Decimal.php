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
 * Two things this class exists to handle:
 *
 * 1. bcmath *truncates* to the requested scale rather than rounding. `bcadd('0.999',
 *    '0', 2)` is '0.99', not '1.00'. Every rounding rule is therefore implemented
 *    explicitly here instead of being delegated to the bc* scale argument.
 *
 * 2. PHP 8.4 added bcround() and BcMath\Number, which would do much of this. They are
 *    deliberately not used: composer.json requires PHP ^8.3 and CI runs an 8.3 matrix
 *    leg, so the implementation must work without them.
 */
final class Decimal
{
    /**
     * Scale for intermediate results. Sits well above any storage scale so that
     * products and quotients keep full precision until an explicit rounding step.
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
     * Re-express a value at an exact scale. Truncates toward zero when the value
     * carries more decimals than requested — callers wanting a rounding rule applied
     * must use round() instead.
     *
     * @return numeric-string
     */
    public static function atScale(string $value, int $scale): string
    {
        self::assertValid($value);
        self::assertScale($scale);

        return self::normaliseZero(bcadd($value, '0', $scale));
    }

    /**
     * Round a decimal string to the given scale using the given rule.
     *
     * Implemented on the absolute value, with the sign reapplied at the end, so that
     * every mode has a single definition of "away from zero" rather than each needing
     * separate positive and negative branches.
     *
     * @return numeric-string
     */
    public static function round(string $value, int $scale, RoundingMode $mode): string
    {
        self::assertValid($value);
        self::assertScale($scale);

        if (self::scaleOf($value) <= $scale) {
            return self::atScale($value, $scale);
        }

        $negative = bccomp($value, '0', self::WORKING_SCALE) < 0;
        $absolute = $negative ? bcmul($value, '-1', self::WORKING_SCALE) : $value;

        $truncated = bcadd($absolute, '0', $scale);
        $remainder = bcsub($absolute, $truncated, self::WORKING_SCALE);

        $hasRemainder = bccomp($remainder, '0', self::WORKING_SCALE) > 0;
        $tie = bccomp($remainder, self::halfUnit($scale), self::WORKING_SCALE);

        $awayFromZero = match ($mode) {
            RoundingMode::Up => $hasRemainder,
            RoundingMode::Down => false,
            RoundingMode::Ceiling => $hasRemainder && ! $negative,
            RoundingMode::Floor => $hasRemainder && $negative,
            RoundingMode::HalfUp => $tie >= 0,
            RoundingMode::HalfDown => $tie > 0,
            RoundingMode::HalfEven => $tie > 0 || ($tie === 0 && self::isOdd($truncated, $scale)),
        };

        $result = $awayFromZero
            ? bcadd($truncated, self::unit($scale), $scale)
            : $truncated;

        return self::normaliseZero($negative ? bcmul($result, '-1', $scale) : $result);
    }

    /**
     * One unit at the given scale: scale 2 → '0.01', scale 0 → '1'.
     *
     * @return numeric-string
     */
    private static function unit(int $scale): string
    {
        return bcdiv('1', bcpow('10', (string) $scale), $scale);
    }

    /**
     * Half a unit at the given scale: scale 2 → '0.005', scale 0 → '0.5'.
     *
     * @return numeric-string
     */
    private static function halfUnit(int $scale): string
    {
        return bcdiv('5', bcpow('10', (string) ($scale + 1)), $scale + 1);
    }

    /**
     * Whether the last retained digit is odd — the tie-break for HalfEven.
     *
     * @param  numeric-string  $truncated
     */
    private static function isOdd(string $truncated, int $scale): bool
    {
        $asInteger = bcmul($truncated, bcpow('10', (string) $scale), 0);

        return bcmod($asInteger, '2') !== '0';
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
