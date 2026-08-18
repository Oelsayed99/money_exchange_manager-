<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whose copy of a statement this is.
 *
 * The owner's words: "there is me mode where it shows the profit I made and its type,
 * and I can print a PDF any time with the mode I chose". It is a choice the operator
 * makes per view, not a permission — see docs/HANDOFF.md §3.
 *
 * The distinction is enforced in the query, not in the interface. Inertia serialises
 * props into the HTML document, so a profit figure hidden by a React condition is
 * still sitting in the page source and in whatever that page is printed from. In
 * Client mode the profit columns are never selected, so there is nothing to leak.
 */
enum StatementMode: string
{
    /** Everything, including margin. */
    case Internal = 'internal';

    /** What the counterparty is entitled to see: the money that moved, and nothing else. */
    case Client = 'client';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $mode): string => $mode->value, self::cases());
    }

    public function showsProfit(): bool
    {
        return $this === self::Internal;
    }

    public function label(): string
    {
        return __('statements.modes.'.$this->value);
    }
}
