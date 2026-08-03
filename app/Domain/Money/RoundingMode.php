<?php

declare(strict_types=1);

namespace App\Domain\Money;

/**
 * Rounding rules selectable per currency.
 *
 * Section 3 requires configurable rounding rules. The rule lives on the currency
 * row rather than in code, because it is a business policy that may differ between
 * currencies and must be changeable without a release.
 */
enum RoundingMode: string
{
    /** Ties away from zero: 2.5 → 3, -2.5 → -3. The default for money. */
    case HalfUp = 'half_up';

    /** Ties toward zero: 2.5 → 2, -2.5 → -2. */
    case HalfDown = 'half_down';

    /** Ties to the nearest even digit: 2.5 → 2, 3.5 → 4. Removes the upward bias of HalfUp across large batches. */
    case HalfEven = 'half_even';

    /** Always away from zero: 2.1 → 3, -2.1 → -3. */
    case Up = 'up';

    /** Always toward zero, i.e. truncation: 2.9 → 2, -2.9 → -2. */
    case Down = 'down';

    /** Always toward positive infinity: 2.1 → 3, -2.9 → -2. */
    case Ceiling = 'ceiling';

    /** Always toward negative infinity: 2.9 → 2, -2.1 → -3. */
    case Floor = 'floor';
}
