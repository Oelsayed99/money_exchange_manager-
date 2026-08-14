<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exceptions;

use App\Models\Transaction;
use DomainException;

final class InvalidPosting extends DomainException
{
    public static function noEntries(): self
    {
        return new self('A posting must contain at least one entry.');
    }

    public static function alreadyReversed(Transaction $transaction): self
    {
        return new self(
            "Transaction #{$transaction->id} has already been reversed. Reversing it again would "
            .'cancel it twice; reverse the reversal instead if the correction was itself wrong.'
        );
    }

    public static function notPosted(Transaction $transaction): self
    {
        return new self(
            "Transaction #{$transaction->id} is {$transaction->status->value} and has nothing to reverse. "
            .'A draft is deleted; only a posted or pending transaction is reversed.'
        );
    }
}
