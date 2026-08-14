<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Domain\Money\Money;
use App\Enums\EntryDirection;
use App\Models\LedgerAccount;
use InvalidArgumentException;

/**
 * One side of a proposed posting, before it is written.
 *
 * Immutable, and validated at construction: an entry whose amount is in a different
 * currency from the account it targets is meaningless, and catching it here means the
 * posting service never has to consider the possibility.
 */
final readonly class EntryDraft
{
    private function __construct(
        public LedgerAccount $ledgerAccount,
        public EntryDirection $direction,
        public Money $amount,
    ) {
        if ($amount->currency->code !== $ledgerAccount->spec()->code) {
            throw CurrencyMismatch::between($amount->currency, $ledgerAccount->spec(), 'post');
        }

        // Direction carries the sign. A negative amount would say the same thing
        // twice and break every sum built on top of it.
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException(
                'A ledger entry must be a positive amount; the direction carries the sign. '
                ."Got [{$amount->toStorageString()}] for [{$ledgerAccount->code}]."
            );
        }
    }

    public static function debit(LedgerAccount $account, Money $amount): self
    {
        return new self($account, EntryDirection::Debit, $amount);
    }

    public static function credit(LedgerAccount $account, Money $amount): self
    {
        return new self($account, EntryDirection::Credit, $amount);
    }

    public function reversed(): self
    {
        return new self($this->ledgerAccount, $this->direction->opposite(), $this->amount);
    }

    /** The effect on the target account's balance: positive increases it. */
    public function signedAmount(): Money
    {
        return $this->ledgerAccount->signFor($this->direction) === 1
            ? $this->amount
            : $this->amount->negated();
    }
}
