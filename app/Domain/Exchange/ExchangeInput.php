<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Domain\Money\Money;
use App\Enums\MovementMethod;
use App\Enums\ProfitMethod;
use App\Enums\SpreadType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * One currency exchange, as an operator describes it.
 *
 * Both amounts are given, never one derived from the other. Section 2 requires the
 * received and delivered legs to be recorded as they happened, and the sample statement
 * bears that out: the operator knew 2,574,000 EGP went out for 50,000 USD. Deriving one
 * side from a rate would replace a fact with a calculation.
 */
final readonly class ExchangeInput
{
    public function __construct(
        public Currency $receivedCurrency,
        public Money $receivedAmount,
        public Account $receivedInto,
        public Currency $deliveredCurrency,
        public Money $deliveredAmount,
        public Account $deliveredFrom,
        public DateTimeInterface $occurredAt,
        public ProfitMethod $profitMethod = ProfitMethod::RateDifference,
        /** Received per unit delivered, at what the delivered currency cost us. */
        public ?string $costRate = null,
        public ?SpreadType $spreadType = null,
        public ?string $spreadValue = null,
        public ?Money $feesCharged = null,
        public ?Money $expenses = null,
        public ?Money $commissions = null,
        public ?Counterparty $counterparty = null,
        public ?MovementMethod $method = null,
        public ?string $reference = null,
        public ?string $description = null,
        public ?string $idempotencyKey = null,
    ) {
        $this->assertCurrency($receivedAmount, $receivedCurrency, 'received');
        $this->assertCurrency($deliveredAmount, $deliveredCurrency, 'delivered');

        if ($receivedCurrency->code === $deliveredCurrency->code) {
            throw new InvalidArgumentException(
                "An exchange must be between two different currencies; both sides are {$receivedCurrency->code}. "
                .'Moving money between accounts in one currency is a transfer.'
            );
        }

        foreach (['receivedAmount' => $receivedAmount, 'deliveredAmount' => $deliveredAmount] as $name => $amount) {
            if (! $amount->isPositive()) {
                throw new InvalidArgumentException("The {$name} of an exchange must be positive.");
            }
        }

        // Profit is measured in the received currency (Section 3 requires the currency
        // to be explicit), so anything added to or taken off it must be in that currency.
        foreach (['fees' => $feesCharged, 'expenses' => $expenses, 'commissions' => $commissions] as $name => $amount) {
            if ($amount !== null && ! $amount->currency->is($receivedCurrency->spec())) {
                throw new CurrencyMismatch(
                    "The {$name} on an exchange are measured in the profit currency "
                    ."({$receivedCurrency->code}), but {$amount->currency->code} was given."
                );
            }
        }
    }

    /** The currency profit is measured in. */
    public function profitCurrency(): Currency
    {
        return $this->receivedCurrency;
    }

    private function assertCurrency(Money $amount, Currency $currency, string $side): void
    {
        if (! $amount->currency->is($currency->spec())) {
            throw CurrencyMismatch::between($amount->currency, $currency->spec(), "record the {$side} leg of");
        }
    }
}
