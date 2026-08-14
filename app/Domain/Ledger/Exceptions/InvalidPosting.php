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

    public static function notADraft(Transaction $transaction): self
    {
        return new self(
            "Transaction #{$transaction->id} is {$transaction->status->value}, not a draft. "
            .'Only a draft can be committed; something already in the ledger is corrected by a reversal.'
        );
    }

    public static function cannotCommitToDraft(): self
    {
        return new self('Committing a draft means posting or marking it pending, not leaving it a draft.');
    }

    public static function draftHasNoPayload(Transaction $transaction): self
    {
        return new self(
            "Draft #{$transaction->id} has no stored inputs, so there is nothing to build entries from."
        );
    }

    public static function onlyDraftsCanBeDiscarded(Transaction $transaction): self
    {
        return new self(
            "Transaction #{$transaction->id} is {$transaction->status->value} and cannot be discarded. "
            .'Only a draft may be deleted, because only a draft has never touched the ledger. '
            .'Reverse it instead.'
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
