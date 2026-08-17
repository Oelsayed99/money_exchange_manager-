<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Money\CurrencySpec;
use App\Domain\Money\Decimal;
use App\Domain\Money\Money;
use DomainException;
use InvalidArgumentException;

/**
 * A rate as a dealer states it: one unit of the base currency buys this much of the quote.
 *
 *     RateQuote::of(USD, AED, '3.67')   →   1 USD = 3.67 AED
 *
 * This exists because an operator does not think in the ledger's terms. The ledger
 * records what was received and what was delivered, and derives the rate from them,
 * which is the right way round for storage: the amounts are the fact and the rate
 * describes them. But at the keyboard the rate is known first and one of the amounts is
 * the unknown. This class solves for whichever of the three is missing.
 *
 * The orientation matters and is not cosmetic. "1 USD = 3.67 AED" and "1 AED = 3.67 USD"
 * are different deals, and which currency is received rather than delivered is a
 * separate question again. Storing the pair rather than a bare number means the quote
 * cannot be read the wrong way round later.
 *
 * ## On precision
 *
 * Converting from the base is a multiplication and is usually exact. Converting from
 * the quote is a division and usually is not: 1,000,000 EGP at 54.20 to the euro is
 * 18,450.1845018450… forever. Unlike Money::multipliedBy, this class does not throw on
 * an inexact result — it truncates and reports {@see Conversion::$exact} as false, so
 * the interface can say so at the moment of entry. Refusing would make rate entry
 * unusable for exactly the deals it is most needed for, and the operator is about to
 * overwrite the figure with the amount actually settled anyway. Nothing is discarded
 * quietly; it is discarded out loud, in front of the person who can correct it.
 */
final readonly class RateQuote
{
    /**
     * Rates are held to twelve decimal places, well beyond any quoted market rate.
     *
     * The canonical definition. {@see ProfitCalculator::RATE_SCALE} defers to it so a
     * rate cannot mean one precision on the way in and another on the way out.
     */
    public const int SCALE = 12;

    /** @param  numeric-string  $rate */
    private function __construct(
        public CurrencySpec $base,
        public CurrencySpec $quote,
        public string $rate,
    ) {}

    /** Construct from an exact decimal string, rejecting anything a rate cannot be. */
    public static function of(CurrencySpec $base, CurrencySpec $quote, string $rate): self
    {
        if ($base->is($quote)) {
            throw new InvalidArgumentException(
                "A rate needs two different currencies; both sides are {$base->code}."
            );
        }

        Decimal::assertValid($rate);

        if (bccomp($rate, '0', Decimal::WORKING_SCALE) <= 0) {
            throw new InvalidArgumentException(
                "A rate must be greater than zero, got [{$rate}]. A zero or negative rate would "
                .'describe currency changing hands for nothing, or for less than nothing.'
            );
        }

        if (Decimal::scaleOf($rate) > self::SCALE) {
            throw new InvalidArgumentException(
                'A rate may carry at most '.self::SCALE." decimal places, got [{$rate}]."
            );
        }

        return new self($base, $quote, $rate);
    }

    /**
     * Solve for the amount on the other side of the quote.
     *
     * The direction is taken from the amount's own currency rather than a flag, so it
     * cannot be passed inconsistently with the money it applies to.
     */
    public function convert(Money $amount): Conversion
    {
        if ($amount->currency->is($this->base)) {
            return $this->scale($amount, $this->rate, $this->quote, multiply: true);
        }

        if ($amount->currency->is($this->quote)) {
            return $this->scale($amount, $this->rate, $this->base, multiply: false);
        }

        throw new DomainException(
            "A {$amount->currency->code} amount cannot be converted by a "
            ."{$this->base->code}/{$this->quote->code} rate; it belongs to neither side."
        );
    }

    /**
     * Derive the quote that the two amounts actually imply.
     *
     * Used once the operator has overwritten a computed figure with the amount really
     * settled: the rate they typed becomes an approximation of the deal, and this is
     * the deal itself. Division, so it truncates at {@see SCALE}.
     */
    public static function between(Money $base, Money $quote): self
    {
        /** @var numeric-string $rate */
        $rate = Decimal::truncateTo(self::ratio($base, $quote), self::SCALE);

        return self::of($base->currency, $quote->currency, $rate);
    }

    /**
     * Whether the rate the two amounts imply survives being held to {@see SCALE}.
     *
     * Paired with {@see between} the way Money::divisionIsExact is paired with
     * Money::dividedBy: the operation always answers, and this says whether the answer
     * is the whole of it.
     */
    public static function betweenIsExact(Money $base, Money $quote): bool
    {
        return ! Decimal::losesPrecisionAt(self::ratio($base, $quote), self::SCALE);
    }

    /** @return numeric-string */
    private static function ratio(Money $base, Money $quote): string
    {
        if ($base->isZero()) {
            throw new DomainException(
                "Cannot derive a rate from a zero {$base->currency->code} amount: every rate "
                .'would satisfy it.'
            );
        }

        /** @var numeric-string $ratio */
        $ratio = bcdiv($quote->toStorageString(), $base->toStorageString(), Decimal::WORKING_SCALE);

        return $ratio;
    }

    /** The same deal stated the other way round. Division, so it may truncate. */
    public function inverted(): self
    {
        /** @var numeric-string $inverse */
        $inverse = Decimal::truncateTo(
            bcdiv('1', $this->rate, Decimal::WORKING_SCALE),
            self::SCALE,
        );

        return self::of($this->quote, $this->base, $inverse);
    }

    /** @param numeric-string $rate */
    private function scale(Money $amount, string $rate, CurrencySpec $into, bool $multiply): Conversion
    {
        $raw = $multiply
            ? bcmul($amount->toStorageString(), $rate, Decimal::WORKING_SCALE)
            : bcdiv($amount->toStorageString(), $rate, Decimal::WORKING_SCALE);

        $exact = ! Decimal::losesPrecisionAt($raw, Money::SCALE);

        return new Conversion(
            amount: Money::of(Decimal::truncateTo($raw, Money::SCALE), $into),
            exact: $exact,
        );
    }
}
