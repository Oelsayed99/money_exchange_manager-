<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which way a movement pushes the running balance with a counterparty.
 *
 * There is one balance per party per currency and it is signed, so there are only two
 * directions. The names say what the number *means* rather than which account is
 * debited, because that is the question anybody reading a statement is asking.
 */
enum ClientEffect: string
{
    /** We paid them: the balance rises. Positive means they owe us. */
    case TheyOweUsMore = 'they_owe_us_more';

    /** We took money from them: the balance falls. Negative means we hold theirs. */
    case WeOweThemMore = 'we_owe_them_more';

    /** Whether the balance goes up. */
    public function increases(): bool
    {
        return $this === self::TheyOweUsMore;
    }
}
