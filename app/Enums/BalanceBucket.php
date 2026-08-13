<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The distinct positions a counterparty can hold against the business.
 *
 * Section 5 is explicit that these must not be combined into one balance field, and
 * the reason is not tidiness. A party can simultaneously owe money *and* hold money on
 * the business's behalf; netting those into a single figure destroys the information
 * needed to chase one and reconcile the other, and the loss is invisible until someone
 * disputes a statement.
 *
 * Custody and credit/trust are mirrors of each other, which is exactly why they are
 * separate cases:
 *
 *   Custody      — the business's money, physically held by them.   (asset)
 *   Receivable   — their money, owed to the business.               (asset)
 *   Payable      — the business's money, owed to them.              (liability)
 *   CreditTrust  — their money, physically held by the business.    (liability)
 */
enum BalanceBucket: string
{
    case Custody = 'custody';
    case Receivable = 'receivable';
    case Payable = 'payable';
    case CreditTrust = 'credit_trust';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $bucket): string => $bucket->value, self::cases());
    }

    public function label(): string
    {
        return __('counterparties.buckets.'.$this->value);
    }

    /** Money the business owns or is owed. */
    public function isAsset(): bool
    {
        return in_array($this, [self::Custody, self::Receivable], true);
    }

    /** Money the business owes or is holding for somebody else. */
    public function isLiability(): bool
    {
        return ! $this->isAsset();
    }

    /**
     * The bucket that mirrors this one across the two sides of the relationship.
     *
     * Useful for reporting: the pairing is what makes "they hold £X of mine while I
     * hold £Y of theirs" expressible instead of collapsing to a single net number.
     */
    public function mirror(): self
    {
        return match ($this) {
            self::Custody => self::CreditTrust,
            self::CreditTrust => self::Custody,
            self::Receivable => self::Payable,
            self::Payable => self::Receivable,
        };
    }
}
