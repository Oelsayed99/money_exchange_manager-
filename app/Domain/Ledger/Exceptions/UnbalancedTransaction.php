<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exceptions;

use App\Domain\Money\Money;
use DomainException;

/**
 * Thrown when a proposed posting does not balance within a currency.
 *
 * The central invariant of the ledger. Note that it is checked per currency and
 * without reference to any exchange rate — which is precisely why a posted transaction
 * cannot drift when rates change later.
 */
final class UnbalancedTransaction extends DomainException
{
    public static function inCurrency(string $currency, Money $debits, Money $credits): self
    {
        $difference = $debits->minus($credits);

        return new self(
            "Transaction does not balance in {$currency}: debits {$debits->toDisplayString()} "
            ."against credits {$credits->toDisplayString()}, a difference of {$difference->toDisplayString()}. "
            .'Every transaction must balance independently within each currency it touches.'
        );
    }
}
