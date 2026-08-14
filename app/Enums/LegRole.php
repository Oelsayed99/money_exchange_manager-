<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a transaction leg represents (Section 2).
 *
 * A leg is the human-readable description of a flow — what entered custody, what left,
 * and between whom. The ledger entries are the accounting; legs are what a statement
 * shows.
 */
enum LegRole: string
{
    /** Money that entered custody. */
    case Received = 'received';

    /** Money that left custody. */
    case Delivered = 'delivered';

    case Fee = 'fee';
    case Expense = 'expense';
    case Commission = 'commission';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }
}
