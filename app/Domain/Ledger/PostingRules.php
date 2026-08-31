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

            // The two client movements. Everything a party can do with us is one of
            // them; which way the running balance ends up is the answer, not the input.
            TransactionType::In => $this->clientMovement($input, incoming: true),
            TransactionType::Out => $this->clientMovement($input, incoming: false),

            TransactionType::Refund => $this->clientMovement($input, incoming: false),

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
            // The rate a converted movement was agreed at. Both amounts are already in
            // the entries; without this the statement can say what moved but not what
            // it was turned at, which is half of the detail the operator typed.
            attributes: $input->converts() && $input->rate !== null
                ? ['customer_rate' => $input->rate]
                : [],
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

        // A counterparty's opening position: one signed figure, not four. Positive
        // means they owed us on day one, negative that we were holding theirs.
        if ($input->counterparty !== null) {
            $target = $this->accounts->forCounterparty($input->counterparty, $input->currency);
            $owesUs = ! $input->amount->isNegative();
            $amount = $input->amount->absolute();

            return [
                $owesUs
                    ? [EntryDraft::debit($target, $amount), EntryDraft::credit($equity, $amount)]
                    : [EntryDraft::debit($equity, $amount), EntryDraft::credit($target, $amount)],
                [new LegDraft(
                    $owesUs ? LegRole::Delivered : LegRole::Received,
                    $amount,
                    $input->currency->id,
                    null,
                    $input->counterparty->id,
                )],
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
     * Money in from a client, or out to one — and the whole of the relationship.
     *
     * ## The simple case
     *
     * Cash moves one way, the client's running balance the other:
     *
     *     in    DR cash · CUR          CR client · CUR
     *     out   DR client · CUR        CR cash · CUR
     *
     * ## When the two are in different currencies
     *
     * Take 10,000 dollars and record it against the client as pounds at 50.85. The
     * dollars really arrived, and the client's account really moved by 508,500 — both
     * are facts, in different currencies, so they are joined the way an exchange joins
     * its legs, through the clearing accounts:
     *
     *     DR cash · USD            10,000     CR fx_position · USD    10,000
     *     DR fx_position · EGP    508,500     CR client · EGP        508,500
     *
     * Each currency balances on its own, so the invariant holds and no exchange rate
     * appears in the integrity check. The rate is stored as a description of what
     * happened, exactly as it is on an exchange, and the position is flat at that rate
     * by construction — the operator's rate *is* the rate of record, so there is no
     * margin here to recognise. Margins are priced on the exchange screen.
     *
     * @return array{list<EntryDraft>, list<LegDraft>}
     */
    private function clientMovement(TransactionInput $input, bool $incoming): array
    {
        $client = $this->accounts->forCounterparty($input->requireCounterparty(), $input->currency);
        $cash = $this->accounts->forAccount($input->requireAccount(), $input->movedCurrency());
        $moved = $input->movedAmount();

        if (! $input->converts()) {
            $entries = $incoming
                ? [EntryDraft::debit($cash, $moved), EntryDraft::credit($client, $moved)]
                : [EntryDraft::debit($client, $moved), EntryDraft::credit($cash, $moved)];

            return [$entries, [$this->clientLegs($input, $incoming)[0]]];
        }

        $cashClearing = $this->accounts->system(LedgerAccountSubkind::FxPosition, $input->movedCurrency());
        $bookClearing = $this->accounts->system(LedgerAccountSubkind::FxPosition, $input->currency);

        $entries = $incoming
            ? [
                EntryDraft::debit($cash, $moved),
                EntryDraft::credit($cashClearing, $moved),
                EntryDraft::debit($bookClearing, $input->amount),
                EntryDraft::credit($client, $input->amount),
            ]
            : [
                EntryDraft::debit($client, $input->amount),
                EntryDraft::credit($bookClearing, $input->amount),
                EntryDraft::debit($cashClearing, $moved),
                EntryDraft::credit($cash, $moved),
            ];

        return [$entries, $this->clientLegs($input, $incoming)];
    }

    /**
     * What moved, as the transaction list shows it.
     *
     * The cash leg first, because that is the money somebody handed over or took away.
     * When a conversion happened the recorded side is a second leg, so the row reads
     * "10,000 USD in, 508,500 EGP against the account" rather than picking one.
     *
     * @return list<LegDraft>
     */
    private function clientLegs(TransactionInput $input, bool $incoming): array
    {
        $legs = [new LegDraft(
            $incoming ? LegRole::Received : LegRole::Delivered,
            $input->movedAmount(),
            $input->movedCurrency()->id,
            $input->account?->id,
            $input->counterparty?->id,
        )];

        if ($input->converts()) {
            $legs[] = new LegDraft(
                $incoming ? LegRole::Delivered : LegRole::Received,
                $input->amount,
                $input->currency->id,
                null,
                $input->counterparty?->id,
            );
        }

        return $legs;
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

    private function receivedLeg(TransactionInput $input): LegDraft
    {
        return new LegDraft(LegRole::Received, $input->amount, $input->currency->id, $input->account?->id, $input->counterparty?->id);
    }

    private function deliveredLeg(TransactionInput $input): LegDraft
    {
        return new LegDraft(LegRole::Delivered, $input->amount, $input->currency->id, $input->account?->id, $input->counterparty?->id);
    }
}
