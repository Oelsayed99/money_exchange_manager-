<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the profit on a transaction is arrived at (Section 3).
 *
 * These are the whole list. There was once a "Spread" method that then asked a second
 * question — was the number units per unit, or a percentage? — and the two answers were
 * the only thing that made any difference to the arithmetic. A question whose answer is
 * itself a method is a method; both are now here, named, in one list.
 */
enum ProfitMethod: string
{
    /** Customer rate against cost rate. The difference is the margin. */
    case RateDifference = 'rate_difference';

    /**
     * Currency units of margin per unit exchanged.
     *
     * Section 3: "Do not assume that 0.02 always means 2%. It may mean a rate spread of
     * 0.02 currency units for each exchanged currency unit." This is that reading —
     * 0.02 on a rate of 3.67 means the currency cost 3.65.
     */
    case PerUnit = 'per_unit';

    /**
     * A percentage of the value exchanged.
     *
     * The other reading of the same number, and on a 50,000 deal the two differ by a
     * factor of about fifty. Which is why they are two entries and not one.
     */
    case Percentage = 'percentage';

    /** A stated amount, agreed in advance and independent of the rate. */
    case FixedAmount = 'fixed_amount';

    /** Typed in by the operator for this deal alone. */
    case Manual = 'manual';

    /** Moving money between our own accounts. There is no margin to record. */
    case None = 'none';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $method): string => $method->value, self::cases());
    }

    public function label(): string
    {
        return __('transactions.profit_methods.'.$this->value);
    }

    /** Whether a cost rate is needed to work the profit out. */
    public function needsCostRate(): bool
    {
        return $this === self::RateDifference;
    }

    /**
     * Whether the operator states the profit directly.
     *
     * Fixed and manual compute identically; they are separate because reporting needs
     * to tell an agreed standing margin from a one-off negotiated figure.
     */
    public function isStatedDirectly(): bool
    {
        return in_array($this, [self::FixedAmount, self::Manual], true);
    }

    /**
     * Whether a figure has to be typed alongside the method.
     *
     * Every method except a rate difference — which reads its figure from the cost rate
     * — and no profit at all, which has nothing to read.
     */
    public function needsValue(): bool
    {
        return in_array($this, [self::PerUnit, self::Percentage, self::FixedAmount, self::Manual], true);
    }

    /** What the figure beside the method means, for labelling the field. */
    public function valueLabel(): string
    {
        return __('transactions.profit_values.'.$this->value);
    }
}
