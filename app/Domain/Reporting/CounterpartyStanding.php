<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Money\Money;
use App\Enums\BalanceBucket;

/**
 * Where one counterparty stands in one currency.
 *
 * Two figures, not four. The four buckets are still what the ledger holds and what a
 * statement shows; this is the summary a list needs — our money in their hands against
 * their money in ours.
 *
 * ## Why summing within a side is allowed and summing across is not
 *
 * ADR 0007 forbids one balance field per party, because netting a receivable against a
 * payable gives a number that is right in total and useless in practice: it cannot tell
 * you what to chase or what to settle. **That prohibition is about the two sides.**
 *
 * Custody and receivable are both our money with them, differing in how it got there —
 * left with them, or owed by them. Adding those two is a summary of one side, and the
 * split is a click away on the statement. Adding the sides together is the thing that
 * destroys information, and nothing here does it: `ours` and `theirs` are separate
 * fields with no method that combines them.
 */
final readonly class CounterpartyStanding
{
    /**
     * @param  array<string, Money>  $buckets  bucket value => amount, for the drill-down
     */
    public function __construct(
        public string $code,
        public Money $ours,
        public Money $theirs,
        public array $buckets,
    ) {}

    /**
     * Build a standing from the buckets that carry a balance in one currency.
     *
     * @param  array<string, Money>  $buckets
     */
    public static function of(string $code, array $buckets, Money $zero): self
    {
        $ours = $zero;
        $theirs = $zero;

        foreach ($buckets as $value => $amount) {
            if (BalanceBucket::from($value)->isAsset()) {
                $ours = $ours->plus($amount);
            } else {
                $theirs = $theirs->plus($amount);
            }
        }

        return new self($code, $ours, $theirs, $buckets);
    }

    public function isEmpty(): bool
    {
        return $this->ours->isZero() && $this->theirs->isZero();
    }
}
