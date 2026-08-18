<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Money\Money;
use App\Enums\CounterpartyStatus;

/**
 * One counterparty's standing, on the dashboard's list.
 *
 * Positions are held per currency and per bucket, and there is no field here holding a
 * single figure for the relationship. A party owing dollars while holding pounds on
 * deposit has two live positions; the only honest summary of that is "mixed", which is
 * what {@see $status} says.
 */
final readonly class CounterpartyPosition
{
    /**
     * @param  array<string, array<string, Money>>  $positions  currency code => bucket => balance
     * @param  array<string, CounterpartyStatus>  $statusByCurrency  currency code => status
     */
    public function __construct(
        public int $id,
        public string $name,
        public CounterpartyStatus $status,
        public array $positions,
        public array $statusByCurrency,
    ) {}
}
