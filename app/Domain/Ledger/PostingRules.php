<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Enums\LedgerAccountSubkind;
use App\Enums\LegRole;
use App\Enums\TransactionType;
use App\Models\LedgerAccount;
use DomainException;

/**
 * Turns "a credit deposit of 581,000 EGP from Salem" into the entries that represent it.
 *
 * Every rule from docs/posting-rules.md §3 lives in this one class, deliberately.
 * Correctness here is judged by reading the rules *against each other* — that a
 * receivable settlement mirrors a payable settlement, that credit deposit and credit
 * settlement are exact opposites — and that comparison is impossible when each rule
 * sits in its own file. One screen, nineteen rules, side by side.
 *
 * Currency exchange is not here: it needs two amounts and a rate, and arrives with the
 * profit engine in Phase 4.
 */
final class PostingRules
{
    public function __construct(private readonly LedgerAccountResolver $accounts) {}

    public function build(TransactionInput $input): PostingRequest
    {
        [$entries, $legs] = match ($input->type) {
            TransactionType::OpeningBalance => $this->openingBalance($input),
            TransactionType::Deposit => $this->capitalIn($input),
            TransactionType::Withdrawal => $this->capitalOut($input),
            TransactionType::Transfer => $this->transfer($input),

            // Money in against what they owe. A settlement is the same posting with a
            // different intent, and the type on the transaction is what tells them
            // apart in reporting.
            TransactionType::MoneyReceived,
            TransactionType::ReceivableSettlement => $this->cashIn($input, LedgerAccountSubkind::Receivable),

            TransactionType::MoneyPaid,
            TransactionType::PayableSettlement,
            TransactionType::Refund => $this->cashOut($input, LedgerAccountSubkind::Payable),

            // Lending creates a receivable; borrowing creates a payable. Same buckets
            // as trade balances, distinguished by the type — see §2 of the document.
            TransactionType::LoanGiven => $this->cashOut($input, LedgerAccountSubkind::Receivable),
            TransactionType::LoanReceived => $this->cashIn($input, LedgerAccountSubkind::Payable),

            TransactionType::CreditDeposit => $this->cashIn($input, LedgerAccountSubkind::CreditTrust),
            TransactionType::CreditSettlement => $this->cashOut($input, LedgerAccountSubkind::CreditTrust),

            TransactionType::Fee => $this->fee($input),
            TransactionType::Expense => $this->expense($input),
            TransactionType::ProfitAdjustment => $this->profitAdjustment($input),
            TransactionType::BalanceAdjustment => $this->balanceAdjustment($input),

            TransactionType::CurrencyExchange => throw new DomainException(
                'Currency exchange needs two amounts and a rate. It is built by the exchange '
                .'service in Phase 4, not by this rule set.'
            ),

            TransactionType::Reversal => throw new DomainException(
                'A reversal is never built from an input. Use PostingService::reverse(), which '
                .'mirrors the original entries rather than recomputing them.'
            ),
        };

        return new PostingRequest(
            type: $input->type,
            occurredAt: $input->occurredAt,
            entries: $entries,
            legs: $legs,
            counterparty: $input->counterparty,
            method: $input->method,
            reference: $input->reference,
            description: $input->description,
            idempotencyKey: $input->idempotencyKey,
            status: $input->status,
        );
    }

    /**
     * The declared starting position, so even it has an entry behind it.
     *
     * @return array{list<EntryDraft>, list<LegDraft>}
     */
    private function openingBalance(TransactionInput $input): array
    {
        $equity = $this->accounts->system(LedgerAccountSubkind::OpeningEquity, $input->currency);

        // A counterparty's opening position, in one of the four buckets.
        if ($input->counterparty !== null) {
            $bucket = $input->requireBucket();
            $target = $this->accounts->forBucket($bucket, $input->counterparty, $input->currency);

            return [
                $bucket->isAsset()
                    ? [EntryDraft::debit($target, $input->amount), EntryDraft::credit($equity, $input->amount)]
                    : [EntryDraft::debit($equity, $input->amount), EntryDraft::credit($target, $input->amount)],
                [],
            ];
        }

        $cash = $this->cashAccount($input);

        return [
            [EntryDraft::debit($cash, $input->amount), EntryDraft::credit($equity, $input->amount)],
            [$this->receivedLeg($input)],
        ];
    }

    /** @return array{list<EntryDraft>, list<LegDraft>} */
    private function capitalIn(TransactionInput $input): array
    {
        $capital = $this->accounts->system(LedgerAccountSubkind::Capital, $input->currency);

        return [
            [EntryDraft::debit($this->cashAccount($input), $input->amount), EntryDraft::credit($capital, $input->amount)],
            [$this->receivedLeg($input)],
        ];
    }

    /** @return array{list<EntryDraft>, list<LegDraft>} */
    private function capitalOut(TransactionInput $input): array
    {
        $capital = $this->accounts->system(LedgerAccountSubkind::Capital, $input->currency);

        return [
            [EntryDraft::debit($capital, $input->amount), EntryDraft::credit($this->cashAccount($input), $input->amount)],
            [$this->deliveredLeg($input)],
        ];
    }

    /** @return array{list<EntryDraft>, list<LegDraft>} */
    private function transfer(TransactionInput $input): array
    {
        $from = $this->accounts->forAccount($input->requireAccount(), $input->currency);
        $to = $this->accounts->forAccount($input->requireDestinationAccount(), $input->currency);

        if ($from->is($to)) {
            throw new DomainException('A transfer must move money between two different accounts.');
        }

        return [
            [EntryDraft::debit($to, $input->amount), EntryDraft::credit($from, $input->amount)],
            [
                new LegDraft(LegRole::Delivered, $input->amount, $input->currency->id, $input->account?->id),
                new LegDraft(LegRole::Received, $input->amount, $input->currency->id, $input->destinationAccount?->id),
            ],
        ];
    }

    /**
     * Money into a custody location, against one of the counterparty's buckets.
     *
     * @return array{list<EntryDraft>, list<LegDraft>}
     */
    private function cashIn(TransactionInput $input, LedgerAccountSubkind $subkind): array
    {
        $bucket = $this->counterpartyAccount($input, $subkind);

        return [
            [EntryDraft::debit($this->cashAccount($input), $input->amount), EntryDraft::credit($bucket, $input->amount)],
            [$this->receivedLeg($input)],
        ];
    }

    /** @return array{list<EntryDraft>, list<LegDraft>} */
    private function cashOut(TransactionInput $input, LedgerAccountSubkind $subkind): array
    {
        $bucket = $this->counterpartyAccount($input, $subkind);

        return [
            [EntryDraft::debit($bucket, $input->amount), EntryDraft::credit($this->cashAccount($input), $input->amount)],
            [$this->deliveredLeg($input)],
        ];
    }

    /** @return array{list<EntryDraft>, list<LegDraft>} */
    private function fee(TransactionInput $input): array
    {
        $income = $this->accounts->system(LedgerAccountSubkind::FeesIncome, $input->currency);

        return [
            [EntryDraft::debit($this->cashAccount($input), $input->amount), EntryDraft::credit($income, $input->amount)],
            [new LegDraft(LegRole::Fee, $input->amount, $input->currency->id, $input->account?->id, $input->counterparty?->id)],
        ];
    }

    /** @return array{list<EntryDraft>, list<LegDraft>} */
    private function expense(TransactionInput $input): array
    {
        $expense = $this->accounts->system(LedgerAccountSubkind::Expense, $input->currency);

        return [
            [EntryDraft::debit($expense, $input->amount), EntryDraft::credit($this->cashAccount($input), $input->amount)],
            [new LegDraft(LegRole::Expense, $input->amount, $input->currency->id, $input->account?->id, $input->counterparty?->id)],
        ];
    }

    /** @return array{list<EntryDraft>, list<LegDraft>} */
    private function profitAdjustment(TransactionInput $input): array
    {
        $profit = $this->accounts->system(LedgerAccountSubkind::TradingProfit, $input->currency);
        $adjustment = $this->accounts->system(LedgerAccountSubkind::AdjustmentEquity, $input->currency);

        return [
            [EntryDraft::debit($adjustment, $input->amount), EntryDraft::credit($profit, $input->amount)],
            [],
        ];
    }

    /**
     * A counted discrepancy. Section 7 forbids editing a balance directly; a correction
     * is a transaction like any other, with a reason and an audit trail behind it.
     *
     * @return array{list<EntryDraft>, list<LegDraft>}
     */
    private function balanceAdjustment(TransactionInput $input): array
    {
        $cash = $this->cashAccount($input);
        $adjustment = $this->accounts->system(LedgerAccountSubkind::AdjustmentEquity, $input->currency);

        return [
            [EntryDraft::debit($cash, $input->amount), EntryDraft::credit($adjustment, $input->amount)],
            [],
        ];
    }

    private function cashAccount(TransactionInput $input): LedgerAccount
    {
        return $this->accounts->forAccount($input->requireAccount(), $input->currency);
    }

    private function counterpartyAccount(TransactionInput $input, LedgerAccountSubkind $subkind): LedgerAccount
    {
        $bucket = $subkind->bucket();

        if ($bucket === null) {
            throw new DomainException("[{$subkind->value}] is not a counterparty position.");
        }

        return $this->accounts->forBucket($bucket, $input->requireCounterparty(), $input->currency);
    }

    private function receivedLeg(TransactionInput $input): LegDraft
    {
        return new LegDraft(LegRole::Received, $input->amount, $input->currency->id, $input->account?->id, $input->counterparty?->id);
    }

    private function deliveredLeg(TransactionInput $input): LegDraft
    {
        return new LegDraft(LegRole::Delivered, $input->amount, $input->currency->id, $input->account?->id, $input->counterparty?->id);
    }
}
