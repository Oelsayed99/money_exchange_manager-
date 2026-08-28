<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which leg of the deal the margin is measured on.
 *
 * An exchange has two legs and the margin can honestly be stated against either. Sell
 * 50,000 USD for 2,574,000 EGP and the margin is in pounds: the dollars cost 51.20 each
 * and fetched 51.48. Buy the same 50,000 USD *with* pounds and the margin is still in
 * pounds — but the leg it hangs off has swapped sides.
 *
 * The application used to assume the received leg always. That is right for a sale and
 * wrong for a purchase, where it forced the operator to enter 0.019531 for a rate they
 * were thinking of as 51.20, and reported the margin in the wrong currency. See
 * ADR 0027.
 *
 * The cost rate is always quoted as *margin currency per unit of the other leg*, so it
 * is applied by multiplication and nothing here ever divides.
 */
enum MarginBasis: string
{
    /** Margin in the received currency; the cost rate is per unit delivered. */
    case Received = 'received';

    /** Margin in the delivered currency; the cost rate is per unit received. */
    case Delivered = 'delivered';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $basis): string => $basis->value, self::cases());
    }
}
