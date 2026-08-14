<?php

declare(strict_types=1);

namespace App\Enums;

enum EntryDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $direction): string => $direction->value, self::cases());
    }

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }
}
