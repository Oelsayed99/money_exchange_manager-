<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of party the business deals with (Section 5).
 *
 * A label, not a permission or a behaviour: the same person can be a customer this
 * week and a supplier the next, and the balances they carry are what actually matter.
 */
enum CounterpartyType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Partner = 'partner';
    case Personal = 'personal';
    case Business = 'business';
    case Employee = 'employee';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }

    public function label(): string
    {
        return __('counterparties.types.'.$this->value);
    }
}
