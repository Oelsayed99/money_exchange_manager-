<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Money\Money;

/**
 * Where one counterparty stands in one currency: a single signed figure.
 *
 * **Positive means they owe us**, negative that we are holding money of theirs. There
 * were two columns here and four positions behind them; the sign carries the whole of
 * that distinction now. See ADR 0032.
 */
final readonly class CounterpartyStanding
{
    public function __construct(
        public string $code,
        public Money $balance,
    ) {}

    public function theyOweUs(): bool
    {
        return $this->balance->isPositive();
    }

    public function isEmpty(): bool
    {
        return $this->balance->isZero();
    }
}
