<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * See docs/posting-rules.md §5.
 */
enum TransactionStatus: string
{
    /** Being prepared. No ledger entries exist yet; deletable per permission. */
    case Draft = 'draft';

    /** Committed to but not complete — funds promised or in flight. Entries exist, marked pending. */
    case Pending = 'pending';

    /** Complete. Entries count towards the confirmed balance. */
    case Posted = 'posted';

    /** Superseded by a reversing transaction. Entries are retained, unchanged. */
    case Reversed = 'reversed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }

    /** Whether entries in this state exist in the ledger at all. */
    public function hasEntries(): bool
    {
        return $this !== self::Draft;
    }

    /** Whether entries in this state count towards the confirmed balance. */
    public function isConfirmed(): bool
    {
        // A reversed transaction keeps its entries and keeps counting; the reversing
        // transaction's opposite entries are what cancel it. Removing it from the
        // confirmed balance as well would cancel it twice.
        return in_array($this, [self::Posted, self::Reversed], true);
    }
}
