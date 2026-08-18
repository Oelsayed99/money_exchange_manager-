<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Domain\Money\Exceptions\PrecisionLoss;
use DivisionByZeroError;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An exact monetary amount in a single currency.
 *
 * Immutable: every operation returns a new instance. Amounts are decimal strings held
 * at SCALE, never PHP floats — `declare(strict_types=1)` plus the `string|int` parameter
 * type means passing a float to of() is a TypeError rather than a silent precision loss.
 *
 * **No operation rounds.** Addition, subtraction and multiplication are exact. Display
 * is exact. The only lossy operation is division, which truncates toward zero at SCALE
 * because a terminating representation cannot be guaranteed — and truncation only ever
 * drops digits, so an amount can never grow.
 *
 * Money of different currencies can never be added, subtracted or ordered. Conversion
 * is an explicit rate-bearing exchange operation, not an implicit coercion.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    /** Internal scale. Every amount is held at exactly this many decimal places. */
    public const int SCALE = 10;

    /**
     * @param  numeric-string  $amount  always held at SCALE decimal places
     */
    private function __construct(
        public string $amount,
        public CurrencySpec $currency,
    ) {}

    /**
     * Construct from an exact decimal string or integer.
     *
     * Rejects values carrying more precision than SCALE rather than quietly discarding
     * the excess: losing a digit is a decision the caller must make explicitly, because
     * whichever way it goes it is somebody's money.
     */
    public static function of(string|int $amount, CurrencySpec $currency): self
    {
        $value = (string) $amount;

        Decimal::assertValid($value);

        if (Decimal::scaleOf($value) > self::SCALE) {
            throw new InvalidArgumentException(
                "Amount [{$value}] carries more than ".self::SCALE.' decimal places, '
                .'which cannot be represented without discarding digits.'
            );
        }

        return new self(Decimal::padTo($value, self::SCALE), $currency);
    }

    public static function zero(CurrencySpec $currency): self
    {
        return new self(Decimal::padTo('0', self::SCALE), $currency);
    }

    /** Exact. */
    public function plus(self $other): self
    {
        $this->assertSameCurrency($other, 'add');

        return new self(
            Decimal::padTo(bcadd($this->amount, $other->amount, self::SCALE), self::SCALE),
            $this->currency,
        );
    }

    /** Exact. */
    public function minus(self $other): self
    {
        $this->assertSameCurrency($other, 'subtract');

        return new self(
            Decimal::padTo(bcsub($this->amount, $other->amount, self::SCALE), self::SCALE),
            $this->currency,
        );
    }

    /**
     * Multiply by a scalar — an exchange rate, a percentage, a quantity.
     *
     * Exact. The factor is a decimal string, not a Money: multiplying money by money is
     * meaningless. If the product would carry more than SCALE decimal places, this
     * throws rather than discarding digits, so a rate too precise to represent is a
     * loud failure instead of a silent one.
     */
    public function multipliedBy(string $factor): self
    {
        Decimal::assertValid($factor);

        $product = bcmul($this->amount, $factor, Decimal::WORKING_SCALE);

        if (Decimal::losesPrecisionAt($product, self::SCALE)) {
            throw PrecisionLoss::inMultiplication($this->amount, $factor, $product, self::SCALE);
        }

        // The product is computed at WORKING_SCALE and so carries trailing zeros. The
        // guard above has already established that nothing significant sits beyond
        // SCALE, so reducing to it here discards zeros only and remains exact.
        return new self(Decimal::truncateTo($product, self::SCALE), $this->currency);
    }

    /**
     * Divide by a scalar.
     *
     * The one operation that cannot be exact: 10 ÷ 3 does not terminate. The quotient
     * is **truncated** toward zero at SCALE, never rounded, so the result can never
     * exceed the true value. Digits are lost at the tenth decimal place — far below the
     * significance of any currency, but lost nonetheless, which is why this is the only
     * method in the class that admits to it.
     */
    public function dividedBy(string $divisor): self
    {
        Decimal::assertValid($divisor);

        if (bccomp($divisor, '0', Decimal::WORKING_SCALE) === 0) {
            throw new DivisionByZeroError('Cannot divide a monetary amount by zero.');
        }

        $quotient = bcdiv($this->amount, $divisor, Decimal::WORKING_SCALE);

        return new self(Decimal::truncateTo($quotient, self::SCALE), $this->currency);
    }

    /** Whether dividing by this divisor would discard a non-zero digit. */
    public function divisionIsExact(string $divisor): bool
    {
        Decimal::assertValid($divisor);

        if (bccomp($divisor, '0', Decimal::WORKING_SCALE) === 0) {
            return false;
        }

        return ! Decimal::losesPrecisionAt(
            bcdiv($this->amount, $divisor, Decimal::WORKING_SCALE),
            self::SCALE,
        );
    }

    public function negated(): self
    {
        return new self(Decimal::padTo(bcmul($this->amount, '-1', self::SCALE), self::SCALE), $this->currency);
    }

    public function absolute(): self
    {
        return $this->isNegative() ? $this->negated() : $this;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) > 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) < 0;
    }

    /** @return int -1, 0 or 1 */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other, 'compare');

        return bccomp($this->amount, $other->amount, self::SCALE);
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /** Unlike compareTo(), this answers false across currencies rather than throwing. */
    public function equals(self $other): bool
    {
        return $this->currency->is($other->currency)
            && bccomp($this->amount, $other->amount, self::SCALE) === 0;
    }

    /**
     * Full internal precision — what goes into a DECIMAL(28,10) column.
     *
     * @return numeric-string
     */
    public function toStorageString(): string
    {
        return $this->amount;
    }

    /**
     * The amount as a person should see it.
     *
     * Exact. Trailing zeros beyond the currency's declared precision are dropped, but a
     * significant digit never is: USD 1000 renders as "1000.00", and USD 1000.123456
     * renders as "1000.123456" rather than being rounded to two places. Display shows
     * what is held, always.
     *
     * @return numeric-string
     */
    public function toDisplayString(): string
    {
        $minimum = $this->currency->decimalPlaces;

        // Drop only trailing zeros, and only down to the currency's own precision.
        $trimmed = $this->amount;

        while (Decimal::scaleOf($trimmed) > $minimum && str_ends_with($trimmed, '0')) {
            $trimmed = substr($trimmed, 0, -1);
        }

        $trimmed = rtrim($trimmed, '.');

        return Decimal::padTo($trimmed === '' ? '0' : $trimmed, $minimum);
    }

    /**
     * The amount grouped for reading: 3957540.00 becomes 3,957,540.00.
     *
     * **Presentation only. The result is not a valid decimal** — `Decimal::isValid`
     * rejects a thousands separator, deliberately, because a grouped figure arriving
     * from a form or an import is ambiguous about which separator means what. Never
     * store this, never send it anywhere it might come back, and never parse it.
     * {@see toStorageString} and {@see toDisplayString} are the ones that round-trip.
     *
     * Exists because a statement is read by people. Grouping is string surgery on the
     * integer part; no arithmetic happens and no digit moves.
     */
    public function toGroupedString(): string
    {
        $amount = $this->toDisplayString();

        $negative = str_starts_with($amount, '-');
        $bare = $negative ? substr($amount, 1) : $amount;

        [$whole, $fraction] = array_pad(explode('.', $bare, 2), 2, null);

        $grouped = strrev(implode(',', str_split(strrev((string) $whole), 3)));

        return ($negative ? '-' : '').$grouped.($fraction === null ? '' : '.'.$fraction);
    }

    /**
     * Money crosses the HTTP boundary as a string, never a JSON number.
     *
     * JavaScript's `number` is IEEE-754 float64, so serialising an amount as a JSON
     * number reintroduces exactly the precision loss the whole type exists to prevent —
     * regardless of how precise the database column is. This is risk R1 in the assessment.
     *
     * @return array{amount: string, currency: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->toDisplayString(),
            'currency' => $this->currency->code,
        ];
    }

    public function __toString(): string
    {
        return $this->toDisplayString();
    }

    private function assertSameCurrency(self $other, string $operation): void
    {
        if (! $this->currency->is($other->currency)) {
            throw CurrencyMismatch::between($this->currency, $other->currency, $operation);
        }
    }
}
