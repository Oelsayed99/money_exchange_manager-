<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Money\Money;

/**
 * The overall picture, per currency.
 *
 * Everything here is keyed by currency code and nothing is ever added across them.
 * There is no base currency (ADR 0003), so a single "total" would need a rate, and a
 * rate would make today's headline figure disagree with yesterday's for reasons that
 * have nothing to do with the business. Three currencies means three columns.
 */
final readonly class Dashboard
{
    /**
     * @param  array<string, Money>  $cashOnHand  what is in our own custody locations
     * @param  array<string, Money>  $owedToUs  receivable and custody, across all parties
     * @param  array<string, Money>  $owedToThem  payable and credit held, across all parties
     * @param  array<string, Money>  $receivedFromParties  value in from counterparties in the period
     * @param  array<string, Money>  $deliveredToParties  value out to counterparties in the period
     * @param  array<string, Money>  $profit  margin recognised in the period
     * @param  list<CounterpartyPosition>  $counterparties
     * @param  array<string, string>  $monthlyProfit  YYYY-MM => amount, only when one currency is chosen
     * @param  list<string>  $currencies  codes appearing anywhere above, in a stable order
     */
    public function __construct(
        public array $cashOnHand,
        public array $owedToUs,
        public array $owedToThem,
        public array $receivedFromParties,
        public array $deliveredToParties,
        public array $profit,
        public array $counterparties,
        public array $monthlyProfit,
        public array $currencies,
    ) {}
}
