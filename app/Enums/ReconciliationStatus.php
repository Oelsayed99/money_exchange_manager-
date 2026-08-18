<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a reconciliation stands.
 *
 * A reconciliation asks one question — does what we actually hold agree with what the
 * ledger says we hold? — and there are only three honest answers: yes, no, and "no, and
 * here is why".
 *
 * Note what is missing: there is no status meaning *fixed*. A reconciliation never
 * changes a balance. If a difference turns out to be a real error, it is corrected by
 * posting a balance adjustment through the ledger like any other movement, and the
 * reconciliation records which transaction did it. Letting a reconciliation write a
 * balance directly would make it a back door around double entry.
 */
enum ReconciliationStatus: string
{
    /** Counted and the ledger agree exactly. */
    case Balanced = 'balanced';

    /** They disagree, and nobody has said why yet. */
    case Open = 'open';

    /** They disagree, and the reason is recorded. */
    case Resolved = 'resolved';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }

    public function label(): string
    {
        return __('reconciliations.statuses.'.$this->value);
    }

    /** Whether this reconciliation still wants somebody's attention. */
    public function needsAttention(): bool
    {
        return $this === self::Open;
    }
}
