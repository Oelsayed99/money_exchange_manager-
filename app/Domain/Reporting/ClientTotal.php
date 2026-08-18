<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Money\Money;

/**
 * One client's two sides, in one currency, for the comparison chart.
 *
 * Two figures rather than one. A bar showing a client's "balance" would have to net an
 * obligation against a holding to decide its length — the thing ADR 0007 exists to
 * prevent — so a client on both sides gets two bars and reads as what they are.
 */
final readonly class ClientTotal
{
    public function __construct(
        public int $id,
        public string $name,
        public Money $owedToUs,
        public Money $owedToThem,
    ) {}

    /** How prominent this client is, for choosing which few to draw. */
    public function magnitude(): Money
    {
        return $this->owedToUs->isGreaterThan($this->owedToThem) ? $this->owedToUs : $this->owedToThem;
    }
}
