<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\LegDraft;
use App\Domain\Ledger\PostingRequest;
use App\Domain\Ledger\PostingService;
use App\Enums\LedgerAccountSubkind;
use App\Enums\LegRole;
use App\Enums\ProfitStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\LedgerAccount;
use App\Models\Transaction;

/**
 * Records a currency exchange: two legs, joined by the clearing accounts, plus profit.
 *
 * The shape from docs/posting-rules.md §3 (type 11). Each currency balances on its own,
 * with no exchange rate anywhere in the integrity check — which is why a posted deal
 * cannot drift when rates move later.
 *
 * Worth stating because it is the reason the clearing accounts exist at all: once the
 * profit entry is made, `fx_position` in the received currency holds exactly the cost
 * value, and `fx_position` in the delivered currency holds the delivered amount. Valued
 * at the cost rate those are the same number, so the pair nets to zero. A non-zero
 * residual across all deals means an unrecognised or mis-stated spread — a standing
 * correctness check rather than plumbing.
 */
final class ExchangeService
{
    public function __construct(
        private readonly ProfitCalculator $calculator,
        private readonly LedgerAccountResolver $accounts,
        private readonly PostingService $posting,
    ) {}

    /** The figures, without recording anything. This is what the live preview calls. */
    public function preview(ExchangeInput $input): ProfitBreakdown
    {
        return $this->calculator->calculate($input);
    }

    public function record(ExchangeInput $input, TransactionStatus $status = TransactionStatus::Posted): Transaction
    {
        // The same calculator the preview used, so what was shown and what is stored
        // cannot diverge — there is only one implementation of the arithmetic.
        $breakdown = $this->calculator->calculate($input);

        return $this->posting->post(new PostingRequest(
            type: TransactionType::CurrencyExchange,
            occurredAt: $input->occurredAt,
            entries: $this->entries($input, $breakdown),
            legs: $this->legs($input, $breakdown),
            counterparty: $input->counterparty,
            method: $input->method,
            reference: $input->reference,
            description: $input->description,
            idempotencyKey: $input->idempotencyKey,
            status: $status,
            attributes: $this->profitColumns($input, $breakdown, $status),
        ));
    }

    /**
     * @return list<EntryDraft>
     */
    private function entries(ExchangeInput $input, ProfitBreakdown $breakdown): array
    {
        $receivedCash = $this->accounts->forAccount($input->receivedInto, $input->receivedCurrency);
        $deliveredCash = $this->accounts->forAccount($input->deliveredFrom, $input->deliveredCurrency);

        $fxReceived = $this->accounts->system(LedgerAccountSubkind::FxPosition, $input->receivedCurrency);
        $fxDelivered = $this->accounts->system(LedgerAccountSubkind::FxPosition, $input->deliveredCurrency);

        $entries = [
            // Received leg: money in, clearing account credited.
            EntryDraft::debit($receivedCash, $input->receivedAmount),
            EntryDraft::credit($fxReceived, $input->receivedAmount),

            // Delivered leg: clearing account debited, money out.
            EntryDraft::debit($fxDelivered, $input->deliveredAmount),
            EntryDraft::credit($deliveredCash, $input->deliveredAmount),
        ];

        $entries = [...$entries, ...$this->profitEntries($input, $breakdown, $fxReceived)];

        return [...$entries, ...$this->costEntries($input, $breakdown, $receivedCash)];
    }

    /**
     * Recognise the margin, in the received currency.
     *
     * Self-balancing within that currency, so the invariant holds. A loss is the same
     * entry with the sides exchanged — entries are always positive, and the direction
     * carries the sign.
     *
     * @return list<EntryDraft>
     */
    private function profitEntries(ExchangeInput $input, ProfitBreakdown $breakdown, LedgerAccount $fxReceived): array
    {
        $gross = $breakdown->grossProfit;

        if ($gross->isZero()) {
            return [];
        }

        $profit = $this->accounts->system(LedgerAccountSubkind::TradingProfit, $input->receivedCurrency);

        return $gross->isPositive()
            ? [EntryDraft::debit($fxReceived, $gross), EntryDraft::credit($profit, $gross)]
            : [EntryDraft::debit($profit, $gross->absolute()), EntryDraft::credit($fxReceived, $gross->absolute())];
    }

    /**
     * Fees charged, expenses incurred, commissions paid. Each pair balances on its own.
     *
     * @return list<EntryDraft>
     */
    private function costEntries(ExchangeInput $input, ProfitBreakdown $breakdown, LedgerAccount $receivedCash): array
    {
        $entries = [];

        if ($breakdown->feesCharged->isPositive()) {
            $entries[] = EntryDraft::debit($receivedCash, $breakdown->feesCharged);
            $entries[] = EntryDraft::credit(
                $this->accounts->system(LedgerAccountSubkind::FeesIncome, $input->receivedCurrency),
                $breakdown->feesCharged,
            );
        }

        if ($breakdown->expenses->isPositive()) {
            $entries[] = EntryDraft::debit(
                $this->accounts->system(LedgerAccountSubkind::Expense, $input->receivedCurrency),
                $breakdown->expenses,
            );
            $entries[] = EntryDraft::credit($receivedCash, $breakdown->expenses);
        }

        if ($breakdown->commissions->isPositive()) {
            $entries[] = EntryDraft::debit(
                $this->accounts->system(LedgerAccountSubkind::CommissionExpense, $input->receivedCurrency),
                $breakdown->commissions,
            );
            $entries[] = EntryDraft::credit($receivedCash, $breakdown->commissions);
        }

        return $entries;
    }

    /**
     * Section 2 requires the two legs to be recorded as they happened.
     *
     * @return list<LegDraft>
     */
    private function legs(ExchangeInput $input, ProfitBreakdown $breakdown): array
    {
        $legs = [
            new LegDraft(
                LegRole::Received,
                $input->receivedAmount,
                $input->receivedCurrency->id,
                $input->receivedInto->id,
                $input->counterparty?->id,
            ),
            new LegDraft(
                LegRole::Delivered,
                $input->deliveredAmount,
                $input->deliveredCurrency->id,
                $input->deliveredFrom->id,
                $input->counterparty?->id,
            ),
        ];

        foreach ([
            [LegRole::Fee, $breakdown->feesCharged],
            [LegRole::Expense, $breakdown->expenses],
            [LegRole::Commission, $breakdown->commissions],
        ] as [$role, $amount]) {
            // Always a Money on a breakdown; zero simply means there was none.
            if ($amount->isPositive()) {
                $legs[] = new LegDraft($role, $amount, $input->receivedCurrency->id, $input->receivedInto->id, $input->counterparty?->id);
            }
        }

        return $legs;
    }

    /**
     * Inputs and outputs, stored together.
     *
     * Section 3 forbids silent recalculation of old transactions, so the figures are
     * written once and never recomputed. Finalised at the moment of posting; a pending
     * deal is still only an estimate.
     *
     * @return array<string, mixed>
     */
    private function profitColumns(ExchangeInput $input, ProfitBreakdown $breakdown, TransactionStatus $status): array
    {
        return [
            'profit_method' => $input->profitMethod,
            'profit_status' => $status === TransactionStatus::Posted
                ? ProfitStatus::Finalised
                : ProfitStatus::Estimated,
            'profit_currency_id' => $input->profitCurrency()->id,
            'customer_rate' => $breakdown->customerRate,
            'cost_rate' => $breakdown->costRate,
            'spread_type' => $input->spreadType,
            'spread_value' => $input->spreadValue,
            'customer_value' => $breakdown->customerValue->toStorageString(),
            'cost_value' => $breakdown->costValue->toStorageString(),
            'gross_profit' => $breakdown->grossProfit->toStorageString(),
            'fees_charged' => $breakdown->feesCharged->toStorageString(),
            'expenses_amount' => $breakdown->expenses->toStorageString(),
            'commissions_amount' => $breakdown->commissions->toStorageString(),
            'net_profit' => $breakdown->netProfit->toStorageString(),
        ];
    }
}
