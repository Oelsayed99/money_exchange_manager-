<?php

declare(strict_types=1);

namespace App\Domain\Rates;

/**
 * Somewhere to ask what the market is quoting.
 *
 * An interface because the free daily source is a starting point, not a commitment. A
 * business quoting intraday will want an hourly feed behind a key, and swapping one in
 * should be a binding in a service provider rather than an edit to a screen.
 */
interface RateProvider
{
    /** The latest quotes, or null when they could not be had. Never throws. */
    public function latest(): ?ReferenceRates;
}
