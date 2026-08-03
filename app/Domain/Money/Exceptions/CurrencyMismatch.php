<?php

declare(strict_types=1);

namespace App\Domain\Money\Exceptions;

use App\Domain\Money\CurrencySpec;
use DomainException;

/**
 * Thrown when an operation would combine or order two different currencies.
 *
 * There is no exchange rate implied anywhere in the Money type. Converting between
 * currencies is an explicit, rate-bearing, auditable operation that belongs to the
 * exchange domain — never a silent side effect of addition or comparison.
 */
final class CurrencyMismatch extends DomainException
{
    public static function between(CurrencySpec $left, CurrencySpec $right, string $operation): self
    {
        return new self(
            "Cannot {$operation} [{$left->code}] and [{$right->code}]. "
            .'Currency conversion must be an explicit exchange operation carrying a rate.'
        );
    }
}
