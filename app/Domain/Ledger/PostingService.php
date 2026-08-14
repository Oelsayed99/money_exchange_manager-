<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Ledger\Exceptions\InvalidPosting;
use App\Domain\Ledger\Exceptions\UnbalancedTransaction;
use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\Money;
use App\Enums\EntryDirection;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\LedgerBalance;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\TransactionLeg;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The only thing in the system that writes ledger entries.
 *
 * Section 7: "Do not implement transaction screens that directly increment or
 * decrement balance columns without going through the posting service." Everything —
 * every transaction type, every reversal, every opening balance — comes through here,
 * so the invariants below are enforced once rather than remembered nineteen times.
 */
final class PostingService
{
    public function __construct(private readonly CurrencyRegistry $currencies) {}

    /**
     * Post a transaction and its entries.
     *
     * Atomic: the transaction, its legs, its entries and the balances they change are
     * written inside one database transaction, so a partial posting cannot exist.
     */
    public function post(PostingRequest $request): Transaction
    {
        if ($request->entries === []) {
            throw InvalidPosting::noEntries();
        }

        $this->assertBalanced($request->entries);

        // Section 7 requires protection against duplicate submission. Checked before
        // the write and enforced by a unique index behind it, so a genuine race loses
        // at the database rather than posting twice.
        if ($request->idempotencyKey !== null) {
            $existing = Transaction::query()
                ->where('idempotency_key', $request->idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($request): Transaction {
            $transaction = $this->createTransaction($request);

            $this->writeLegs($transaction, $request);
            $entries = $this->writeEntries($transaction, $request);
            $this->applyToBalances($entries, $request->status);

            return $transaction->refresh();
        });
    }

    /**
     * Reverse a transaction by posting its mirror image.
     *
     * Nothing is edited and nothing is deleted. The original keeps every entry and is
     * marked reversed; the reversal carries its own date, so reversing in a later
     * period does not silently rewrite an earlier one.
     */
    public function reverse(Transaction $original, ?string $reason = null, ?\DateTimeInterface $occurredAt = null): Transaction
    {
        if (! $original->status->hasEntries()) {
            throw InvalidPosting::notPosted($original);
        }

        if ($original->status === TransactionStatus::Reversed) {
            throw InvalidPosting::alreadyReversed($original);
        }

        $entries = $original->entries()->with('ledgerAccount')->get();

        // Same account, same amount, opposite side. That is the whole of a reversal —
        // no arithmetic, so nothing can be rounded differently the second time around.
        $drafts = [];

        foreach ($entries as $entry) {
            $drafts[] = $entry->direction->opposite() === EntryDirection::Debit
                ? EntryDraft::debit($entry->account(), $entry->amount)
                : EntryDraft::credit($entry->account(), $entry->amount);
        }

        return DB::transaction(function () use ($original, $drafts, $reason, $occurredAt): Transaction {
            $reversal = $this->post(new PostingRequest(
                type: TransactionType::Reversal,
                occurredAt: $occurredAt ?? now(),
                entries: $drafts,
                counterparty: $original->counterparty,
                reference: $original->reference,
                description: $reason ?? "Reversal of transaction #{$original->id}",
                status: TransactionStatus::Posted,
                reversalOf: $original->id,
            ));

            $original->update(['status' => TransactionStatus::Reversed]);

            return $reversal;
        });
    }

    /**
     * Every transaction must balance independently within each currency it touches.
     *
     * Not in a base currency — per currency. This is checkable without any exchange
     * rate at all, which is exactly why a posted transaction cannot drift when rates
     * move later: no stored value depends on a current rate.
     *
     * @param  list<EntryDraft>  $entries
     */
    private function assertBalanced(array $entries): void
    {
        /** @var array<string, array{debits: Money, credits: Money}> $totals */
        $totals = [];

        foreach ($entries as $entry) {
            $code = $entry->amount->currency->code;

            $totals[$code] ??= [
                'debits' => Money::zero($entry->amount->currency),
                'credits' => Money::zero($entry->amount->currency),
            ];

            $side = $entry->direction === EntryDirection::Debit ? 'debits' : 'credits';

            $totals[$code][$side] = $totals[$code][$side]->plus($entry->amount);
        }

        foreach ($totals as $code => $sides) {
            if (! $sides['debits']->equals($sides['credits'])) {
                throw UnbalancedTransaction::inCurrency($code, $sides['debits'], $sides['credits']);
            }
        }
    }

    private function createTransaction(PostingRequest $request): Transaction
    {
        $posted = $request->status === TransactionStatus::Posted;

        return Transaction::query()->create([
            'type' => $request->type,
            'status' => $request->status,
            'occurred_at' => $request->occurredAt,
            'counterparty_id' => $request->counterparty?->getKey(),
            'method' => $request->method,
            'reference' => $request->reference,
            'description' => $request->description,
            'idempotency_key' => $request->idempotencyKey,
            'reversal_of_transaction_id' => $request->reversalOf,
            'created_by' => Auth::id(),
            'posted_by' => $posted ? Auth::id() : null,
            'posted_at' => $posted ? now() : null,
        ]);
    }

    private function writeLegs(Transaction $transaction, PostingRequest $request): void
    {
        foreach ($request->legs as $sequence => $leg) {
            TransactionLeg::query()->create([
                'transaction_id' => $transaction->id,
                'role' => $leg->role,
                'currency_id' => $leg->currencyId,
                'amount' => $leg->amount->toStorageString(),
                'account_id' => $leg->accountId,
                'counterparty_id' => $leg->counterpartyId,
                'sequence' => $sequence,
            ]);
        }
    }

    /**
     * @return list<LedgerEntry>
     */
    private function writeEntries(Transaction $transaction, PostingRequest $request): array
    {
        $written = [];

        foreach ($request->entries as $sequence => $draft) {
            $written[] = LedgerEntry::query()->create([
                'transaction_id' => $transaction->id,
                'ledger_account_id' => $draft->ledgerAccount->getKey(),
                'currency_id' => $draft->ledgerAccount->currency_id,
                'direction' => $draft->direction,
                'amount' => $draft->amount->toStorageString(),
                'sequence' => $sequence,
                'occurred_at' => $request->occurredAt,
            ]);
        }

        return $written;
    }

    /**
     * Update the cached balances the entries affect.
     *
     * Rows are locked with SELECT … FOR UPDATE in ascending ledger-account id order.
     * The fixed order is what prevents two concurrent postings touching the same pair
     * of accounts from deadlocking against each other.
     *
     * @param  list<LedgerEntry>  $entries
     */
    private function applyToBalances(array $entries, TransactionStatus $status): void
    {
        /** @var array<int, list<LedgerEntry>> $byAccount */
        $byAccount = [];

        foreach ($entries as $entry) {
            $byAccount[$entry->ledger_account_id][] = $entry;
        }

        ksort($byAccount);

        foreach ($byAccount as $ledgerAccountId => $accountEntries) {
            $balance = LedgerBalance::query()
                ->where('ledger_account_id', $ledgerAccountId)
                ->lockForUpdate()
                ->first();

            if ($balance === null) {
                $balance = LedgerBalance::query()->create([
                    'ledger_account_id' => $ledgerAccountId,
                    'confirmed_amount' => '0',
                    'pending_decrease_amount' => '0',
                ]);
            }

            $spec = $this->currencies->byId($accountEntries[0]->currency_id);

            $confirmed = Money::of($balance->confirmed_amount, $spec);
            $pendingDecrease = Money::of($balance->pending_decrease_amount, $spec);

            $lastEntryId = null;

            foreach ($accountEntries as $entry) {
                $lastEntryId = $entry->id;

                $signed = $entry->account()->signFor($entry->direction) === 1
                    ? $entry->amount
                    : $entry->amount->negated();

                if ($status->isConfirmed()) {
                    $confirmed = $confirmed->plus($signed);

                    continue;
                }

                // Pending. Only movements that would *reduce* the account are held
                // back from the available balance; a promised inflow is not spendable.
                if ($signed->isNegative()) {
                    $pendingDecrease = $pendingDecrease->plus($signed->absolute());
                }
            }

            $balance->update([
                'confirmed_amount' => $confirmed->toStorageString(),
                'pending_decrease_amount' => $pendingDecrease->toStorageString(),
                'last_entry_id' => $lastEntryId,
            ]);
        }
    }
}
