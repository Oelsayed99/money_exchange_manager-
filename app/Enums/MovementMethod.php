<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How money physically moved — the ايداع / تحويل / كاش of the source statement.
 *
 * Decided in docs/posting-rules.md §9 (Q1): a field rather than a transaction type,
 * because it is orthogonal to intent. Money can arrive by transfer as a credit
 * deposit, as a receivable settlement, or as capital; making it a type would multiply
 * nineteen types into sixty.
 */
enum MovementMethod: string
{
    /** تحويل — bank or wire transfer. */
    case Transfer = 'transfer';

    /** ايداع — paid in over a counter or at a machine. */
    case Deposit = 'deposit';

    /** كاش — physical cash, hand to hand. */
    case Cash = 'cash';

    case Cheque = 'cheque';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $method): string => $method->value, self::cases());
    }

    public function label(): string
    {
        return __('transactions.methods.'.$this->value);
    }
}
