<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Theme preference (Section 13).
 *
 * System is the default and means "follow the operating system", which is a distinct
 * choice from light or dark rather than the absence of one — a user who picks it wants
 * to keep tracking their OS setting as it changes.
 */
enum Appearance: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $appearance): string => $appearance->value, self::cases());
    }
}
