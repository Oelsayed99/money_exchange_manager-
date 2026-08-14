<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Money\Money;
use App\Enums\ProfitMethod;
use JsonSerializable;

/**
 * The worked-out profit on a deal, with every figure that produced it.
 *
 * Section 3 requires a clear calculation breakdown, and that inputs are stored
 * alongside outputs. This carries both, so a statement can show *why* a number is what
 * it is rather than asking the reader to trust it.
 *
 * Everything is a Money or a decimal string. Nothing here is a float, and nothing
 * crosses the wire as a JSON number.
 */
final readonly class ProfitBreakdown implements JsonSerializable
{
    public function __construct(
        public ProfitMethod $method,
        public Money $delivered,
        public Money $received,
        /** Received per unit delivered, as actually dealt. */
        public string $customerRate,
        /** Received per unit delivered, at what the delivered currency cost us. */
        public ?string $costRate,
        public Money $customerValue,
        public Money $costValue,
        public Money $grossProfit,
        public Money $feesCharged,
        public Money $expenses,
        public Money $commissions,
        public Money $netProfit,
    ) {}

    /** Section 3 requires negative profit to be supported, and warned about before saving. */
    public function isLoss(): bool
    {
        return $this->netProfit->isNegative();
    }

    public function profitCurrency(): string
    {
        return $this->netProfit->currency->code;
    }

    /**
     * Every figure as a string, ready for the preview.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'method' => $this->method->value,
            'delivered' => $this->delivered->jsonSerialize(),
            'received' => $this->received->jsonSerialize(),
            'customer_rate' => $this->customerRate,
            'cost_rate' => $this->costRate,
            'customer_value' => $this->customerValue->jsonSerialize(),
            'cost_value' => $this->costValue->jsonSerialize(),
            'gross_profit' => $this->grossProfit->jsonSerialize(),
            'fees_charged' => $this->feesCharged->jsonSerialize(),
            'expenses' => $this->expenses->jsonSerialize(),
            'commissions' => $this->commissions->jsonSerialize(),
            'net_profit' => $this->netProfit->jsonSerialize(),
            'is_loss' => $this->isLoss(),
            'profit_currency' => $this->profitCurrency(),
        ];
    }
}
