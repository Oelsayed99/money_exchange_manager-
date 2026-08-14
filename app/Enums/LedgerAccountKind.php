<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The accounting nature of a ledger account.
 *
 * Determines which direction increases it, which is the whole of double-entry
 * bookkeeping's arithmetic and the only thing the posting service needs to know
 * about an account in order to sum it correctly.
 */
enum LedgerAccountKind: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    /**
     * A holding account for the open leg of an exchange.
     *
     * Behaves like an asset arithmetically, but is kept distinct because it is never
     * reported as one: a balance sitting in a clearing account is a trade in flight,
     * not something the business owns.
     */
    case Clearing = 'clearing';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::cases());
    }

    /** The direction that increases an account of this kind. */
    public function normalBalance(): EntryDirection
    {
        return match ($this) {
            self::Asset, self::Expense, self::Clearing => EntryDirection::Debit,
            self::Liability, self::Equity, self::Income => EntryDirection::Credit,
        };
    }

    /**
     * The signed effect of an entry on this kind of account.
     *
     * Returns 1 when the direction increases the balance and -1 when it decreases it,
     * so a balance is a single sum rather than two columns subtracted at every call
     * site — the sort of thing that is right in nine places and wrong in the tenth.
     */
    public function signFor(EntryDirection $direction): int
    {
        return $direction === $this->normalBalance() ? 1 : -1;
    }
}
