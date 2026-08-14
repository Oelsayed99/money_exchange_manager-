<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Domain\Money\Money;
use App\Enums\BalanceBucket;
use App\Enums\MovementMethod;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\Transaction;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * What an operator supplies to record a transaction.
 *
 * The union of what every single-amount type needs. Each rule takes only the parts it
 * requires and refuses clearly when something is missing, rather than posting a
 * balanced transaction that describes the wrong event.
 */
final readonly class TransactionInput
{
    public function __construct(
        public TransactionType $type,
        public Currency $currency,
        public Money $amount,
        public DateTimeInterface $occurredAt,
        public ?Account $account = null,
        public ?Account $destinationAccount = null,
        public ?Counterparty $counterparty = null,
        public ?BalanceBucket $bucket = null,
        public ?MovementMethod $method = null,
        public ?string $reference = null,
        public ?string $description = null,
        public ?string $idempotencyKey = null,
        public TransactionStatus $status = TransactionStatus::Posted,
        public ?Transaction $refunds = null,
    ) {
        if (! $amount->currency->is($currency->spec())) {
            throw CurrencyMismatch::between($amount->currency, $currency->spec(), 'record');
        }

        if (! $amount->isPositive()) {
            throw new InvalidArgumentException(
                'A transaction amount must be positive. The transaction type says which way the '
                ."money moved; a negative amount would say it twice. Got [{$amount->toStorageString()}]."
            );
        }
    }

    /** The custody location the money moved into or out of. */
    public function requireAccount(): Account
    {
        return $this->account ?? throw new InvalidArgumentException(
            "A {$this->type->value} needs a custody location, but none was given."
        );
    }

    public function requireDestinationAccount(): Account
    {
        return $this->destinationAccount ?? throw new InvalidArgumentException(
            "A {$this->type->value} needs a destination account, but none was given."
        );
    }

    public function requireCounterparty(): Counterparty
    {
        return $this->counterparty ?? throw new InvalidArgumentException(
            "A {$this->type->value} needs a counterparty, but none was given."
        );
    }

    public function requireBucket(): BalanceBucket
    {
        return $this->bucket ?? throw new InvalidArgumentException(
            "A {$this->type->value} against a counterparty needs a bucket, but none was given."
        );
    }
}
