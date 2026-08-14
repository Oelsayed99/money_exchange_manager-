<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the profit on a transaction is arrived at (Section 3).
 */
enum ProfitMethod: string
{
    /** Customer rate against cost rate. The difference is the margin. */
    case RateDifference = 'rate_difference';

    /** A stated amount, agreed in advance and independent of the rate. */
    case FixedAmount = 'fixed_amount';

    /** A percentage of the value exchanged. */
    case Percentage = 'percentage';

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
}
