<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Domain\Money\Exceptions\CurrencyMismatch;
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
 * Two rules this type enforces that the rest of the system depends on:
 *
 * - Money of different currencies can never be added, subtracted or ordered. Conversion
 *   is an explicit rate-bearing exchange operation, not an implicit coercion.
 * - Addition and subtraction are exact at SCALE and never round. Only multiplication and
 *   division — the operations that genuinely produce new precision — apply a rounding
 *   rule, and they default to the currency's own configured rule.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    /** Internal scale, matching the DECIMAL(28,10) storage columns. */
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
     * Rejects values carrying more precision than SCALE rather than silently rounding
     * them: discarding precision is a decision the caller must make explicitly, because
     * whichever way it goes it is somebody's money.
     */
    public static function of(string|int $amount, CurrencySpec $currency): self
    {
        $value = (string) $amount;

        Decimal::assertValid($value);

        if (Decimal::scaleOf($value) > self::SCALE) {
            throw new InvalidArgumentException(
                "Amount [{$value}] carries more than ".self::SCALE.' decimal places. '
                .'Round it explicitly before constructing Money.'
            );
        }

        return new self(Decimal::atScale($value, self::SCALE), $currency);
    }

    public static function zero(CurrencySpec $currency): self
    {
        return new self(Decimal::atScale('0', self::SCALE), $currency);
    }

    /** Exact: two SCALE-precision values cannot produce a third that needs rounding. */
    public function plus(self $other): self
    {
        $this->assertSameCurrency($other, 'add');

        return new self(
            Decimal::atScale(bcadd($this->amount, $other->amount, self::SCALE), self::SCALE),
            $this->currency,
        );
    }

    /** Exact, for the same reason as plus(). */
    public function minus(self $other): self
    {
        $this->assertSameCurrency($other, 'subtract');

        return new self(
            Decimal::atScale(bcsub($this->amount, $other->amount, self::SCALE), self::SCALE),
            $this->currency,
        );
    }

    /**
     * Multiply by a scalar — an exchange rate, a percentage, a quantity.
     *
     * The factor is a decimal string, not a Money: multiplying money by money is
     * meaningless. Computed at WORKING_SCALE and rounded back to SCALE once, so a
     * rate carrying 12 decimal places does not lose digits mid-calculation.
     */
    public function multipliedBy(string $factor, ?RoundingMode $mode = null): self
    {
        Decimal::assertValid($factor);

        return new self(
            Decimal::round(
                bcmul($this->amount, $factor, Decimal::WORKING_SCALE),
                self::SCALE,
                $mode ?? $this->currency->roundingMode,
            ),
            $this->currency,
        );
    }

    public function dividedBy(string $divisor, ?RoundingMode $mode = null): self
    {
        Decimal::assertValid($divisor);

        if (bccomp($divisor, '0', Decimal::WORKING_SCALE) === 0) {
            throw new DivisionByZeroError('Cannot divide a monetary amount by zero.');
        }

        return new self(
            Decimal::round(
                bcdiv($this->amount, $divisor, Decimal::WORKING_SCALE),
                self::SCALE,
                $mode ?? $this->currency->roundingMode,
            ),
            $this->currency,
        );
    }

    public function negated(): self
    {
        return new self(Decimal::atScale(bcmul($this->amount, '-1', self::SCALE), self::SCALE), $this->currency);
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
     * Rounded to the currency's own precision, using the currency's own rule.
     *
     * This is the point where rounding becomes visible to a person, so it accepts an
     * override for the cases that need one — a report using banker's rounding, or a
     * statement that must match a counterparty's convention. The override on
     * multipliedBy() governs the internal storage scale instead, where the extra
     * digits are still available and the choice rarely changes anything.
     */
    public function toCurrencyScale(?RoundingMode $mode = null): string
    {
        return Decimal::round(
            $this->amount,
            $this->currency->decimalPlaces,
            $mode ?? $this->currency->roundingMode,
        );
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
            'amount' => $this->toCurrencyScale(),
            'currency' => $this->currency->code,
        ];
    }

    public function __toString(): string
    {
        return $this->toCurrencyScale();
    }

    private function assertSameCurrency(self $other, string $operation): void
    {
        if (! $this->currency->is($other->currency)) {
            throw CurrencyMismatch::between($this->currency, $other->currency, $operation);
        }
    }
}
