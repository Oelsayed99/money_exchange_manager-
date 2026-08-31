<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Domain\Money\Money;
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
        /**
         * The money that physically moved, when it is not the currency being recorded.
         *
         * Take 10,000 dollars from a client and record it against them as pounds: the
         * cash side is 10,000 USD, the client's side is the converted figure, and both
         * are facts worth keeping. Null means the two are the same, which is the
         * ordinary case.
         */
        public ?Currency $cashCurrency = null,
        public ?Money $cashAmount = null,
        /** What the two were converted at, kept for the record rather than recomputed. */
        public ?string $rate = null,
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

        if ($cashAmount instanceof Money && $cashCurrency instanceof Currency && ! $cashAmount->currency->is($cashCurrency->spec())) {
            throw CurrencyMismatch::between($cashAmount->currency, $cashCurrency->spec(), 'record the cash side of');
        }

        // An opening position is the one signed amount in the system: it says where a
        // relationship started, and it can start either way round. Everywhere else the
        // type says which way the money moved, and a negative amount would say it twice.
        $signed = $type === TransactionType::OpeningBalance && $counterparty !== null;

        if ($amount->isZero() || (! $signed && $amount->isNegative())) {
            throw new InvalidArgumentException(
                $signed
                    ? 'An opening position of zero says nothing. Leave it out instead.'
                    : 'A transaction amount must be positive. The transaction type says which way the '
                      ."money moved; a negative amount would say it twice. Got [{$amount->toStorageString()}]."
            );
        }
    }

    /**
     * Flatten to something a draft can hold until it is committed.
     *
     * Deliberately stores identifiers rather than serialised models: a draft may sit
     * for days, and it should reflect the account as it is when posted, not a snapshot
     * of how it looked when the draft was started.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'type' => $this->type->value,
            'currency_id' => $this->currency->getKey(),
            'amount' => $this->amount->toStorageString(),
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'account_id' => $this->account?->getKey(),
            'destination_account_id' => $this->destinationAccount?->getKey(),
            'counterparty_id' => $this->counterparty?->getKey(),
            'cash_currency_id' => $this->cashCurrency?->getKey(),
            'cash_amount' => $this->cashAmount?->toStorageString(),
            'rate' => $this->rate,
            'method' => $this->method?->value,
            'reference' => $this->reference,
            'description' => $this->description,
        ];
    }

    /**
     * Rebuild an input from what a draft stored.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        // Cast so the lookups return a single model: find() given an array returns a
        // collection, which is not what any of these fields mean.
        $currency = Currency::query()->findOrFail((int) $payload['currency_id']);

        $cashCurrency = isset($payload['cash_currency_id'])
            ? Currency::query()->find((int) $payload['cash_currency_id'])
            : null;

        return new self(
            type: TransactionType::from((string) $payload['type']),
            currency: $currency,
            amount: $currency->money((string) $payload['amount']),
            occurredAt: new \DateTimeImmutable((string) $payload['occurred_at']),
            account: isset($payload['account_id']) ? Account::query()->find((int) $payload['account_id']) : null,
            destinationAccount: isset($payload['destination_account_id'])
                ? Account::query()->find((int) $payload['destination_account_id'])
                : null,
            counterparty: isset($payload['counterparty_id'])
                ? Counterparty::query()->find((int) $payload['counterparty_id'])
                : null,
            cashCurrency: $cashCurrency,
            cashAmount: $cashCurrency instanceof Currency && isset($payload['cash_amount'])
                ? $cashCurrency->money((string) $payload['cash_amount'])
                : null,
            rate: $payload['rate'] ?? null,
            method: isset($payload['method']) ? MovementMethod::from((string) $payload['method']) : null,
            reference: $payload['reference'] ?? null,
            description: $payload['description'] ?? null,
        );
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

    /** Whether the money that moved was in a different currency from the record. */
    public function converts(): bool
    {
        return $this->cashCurrency instanceof Currency
            && $this->cashAmount instanceof Money
            && ! $this->cashCurrency->spec()->is($this->currency->spec());
    }

    /** The currency the cash actually moved in — the recorded one unless it converted. */
    public function movedCurrency(): Currency
    {
        return $this->converts() && $this->cashCurrency instanceof Currency ? $this->cashCurrency : $this->currency;
    }

    /** The amount of cash that actually moved. */
    public function movedAmount(): Money
    {
        return $this->converts() && $this->cashAmount instanceof Money ? $this->cashAmount : $this->amount;
    }
}
