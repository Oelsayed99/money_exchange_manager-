<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a profit figure is settled (Section 3: "Clearly label estimated and
 * finalized profit").
 */
enum ProfitStatus: string
{
    /** Computed from what is known so far. A draft, or a deal still in flight. */
    case Estimated = 'estimated';

    /** Settled at the moment of posting, and never recomputed afterwards. */
    case Finalised = 'finalised';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
