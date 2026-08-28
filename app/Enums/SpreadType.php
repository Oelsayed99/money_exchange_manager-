<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the number next to "spread" actually means.
 *
 * Section 3 is explicit: "Do not assume that 0.02 always means 2%. It may mean a rate
 * spread of 0.02 currency units for each exchanged currency unit."
 *
 * On a 50,000 USD deal at 51.48 those two readings differ by a factor of about fifty —
 * 1,000 against 51,480. This enum exists so the interface can never present a bare
 * number whose meaning has to be inferred, and so the stored value always says which
 * it was.
 */
enum SpreadType: string
{
    /** Currency units of margin per unit exchanged. 0.02 on a rate of 3.67 means a cost of 3.65. */
    case PerUnit = 'per_unit';

    /** A percentage of the value exchanged. 0.02 entered here would be 0.02%, not 2%. */
    case Percentage = 'percentage';

    /*
     * There was a third case, FixedAmount — a flat sum for the whole deal. It computed
     * `customer value − the figure typed`, which is character for character what
     * ProfitMethod::FixedAmount already did. Two routes to one arithmetic, presented as
     * a choice, is a question with no right answer: an operator picking between "Fixed
     * amount" in one list and "A flat amount for the deal" in another was choosing
     * nothing. Removed in favour of the profit method.
     */

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }

    public function label(): string
    {
        return __('transactions.spread_types.'.$this->value);
    }
}
