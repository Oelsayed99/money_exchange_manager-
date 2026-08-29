<?php

declare(strict_types=1);

namespace App\Domain\Statement;

use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\Money;
use App\Enums\BalanceBucket;
use App\Enums\LedgerOwnerType;
use App\Enums\StatementMode;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Builds a counterparty statement from the ledger.
 *
 * From the ledger, and only from the ledger. Balances are derived everywhere else in
 * this application (`ledger:rebuild`, `ledger:verify`), and a statement that read from
 * a cache or recomputed from transaction rows would be a second opinion — free to
 * disagree with the ledger on the one document a client actually sees.
 */
final class StatementBuilder
{
    public function __construct(private readonly CurrencyRegistry $currencies) {}

    /**
     * @param  Carbon|null  $from  inclusive; earlier entries fold into the opening balance
     * @param  Carbon|null  $to  inclusive to the end of that day
     */
    public function build(
        Counterparty $counterparty,
        Currency $currency,
        StatementMode $mode = StatementMode::Client,
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): CounterpartyStatement {
        $accounts = $this->accountsOf($counterparty, $currency);

        $zero = $currency->zero();
        $running = [];
        $opening = [];
        $totalIn = [];
        $totalOut = [];

        foreach (BalanceBucket::cases() as $bucket) {
            $running[$bucket->value] = $zero;
            $opening[$bucket->value] = $zero;
            $totalIn[$bucket->value] = $zero;
            $totalOut[$bucket->value] = $zero;
        }

        $rows = [];
        $profit = [];
        $profitCounted = [];
        $used = [];

        foreach ($this->entries($accounts, $mode, $to) as $entry) {
            $account = $accounts[$entry->ledger_account_id] ?? null;

            if (! $account instanceof LedgerAccount) {
                throw new RuntimeException(
                    "Entry #{$entry->id} came back from a query restricted to this party's accounts "
                    .'but does not belong to one. The query and the lookup have diverged.'
                );
            }

            $bucket = $account->subkind->bucket();

            if ($bucket === null) {
                throw new RuntimeException(
                    "Ledger account #{$account->id} is owned by a counterparty but its subkind "
                    ."[{$account->subkind->value}] maps to no balance bucket."
                );
            }

            // +1 means the entry increased the account in its own terms; what that says
            // about the relationship depends on which side of the balance sheet it sits.
            $signed = $account->kind->signFor($entry->direction) > 0
                ? $entry->amount
                : $entry->amount->negated();

            $running[$bucket->value] = $running[$bucket->value]->plus($signed);

            // Everything before the period is history: it sets the opening position and
            // is not listed.
            if ($from !== null && $entry->occurred_at->lt($from->copy()->startOfDay())) {
                $opening[$bucket->value] = $running[$bucket->value];

                continue;
            }

            $used[$bucket->value] = true;

            [$in, $out] = $this->split($bucket, $signed);

            $totalIn[$bucket->value] = $totalIn[$bucket->value]->plus($in ?? $currency->zero());
            $totalOut[$bucket->value] = $totalOut[$bucket->value]->plus($out ?? $currency->zero());

            $transaction = $entry->transaction;

            if (! $transaction instanceof Transaction) {
                throw new RuntimeException("Ledger entry #{$entry->id} has no transaction.");
            }

            // The margin belongs to the deal. A deal that touched two of this party's
            // buckets produces two lines, and showing the profit on both would report
            // it twice and total it twice.
            $rowProfit = null;

            if ($mode->showsProfit() && ! isset($profitCounted[$transaction->id])) {
                $profitCounted[$transaction->id] = true;
                $rowProfit = $this->profitOf($transaction);

                if ($rowProfit !== null && ! $rowProfit->isZero()) {
                    $code = $rowProfit->currency->code;
                    $profit[$code] = isset($profit[$code]) ? $profit[$code]->plus($rowProfit) : $rowProfit;
                }
            }

            $rows[] = new StatementRow(
                transactionId: $transaction->id,
                type: $transaction->type,
                occurredAt: $entry->occurred_at,
                reference: $transaction->reference,
                description: $transaction->description,
                bucket: $bucket,
                in: $in,
                out: $out,
                balanceAfter: $running[$bucket->value],
                profit: $rowProfit,
            );
        }

        return new CounterpartyStatement(
            counterparty: $counterparty,
            currency: $currency,
            mode: $mode,
            from: $from,
            to: $to,
            rows: $rows,
            opening: $opening,
            closing: $running,
            buckets: $this->bucketsInPlay($used, $opening, $running),
            totalIn: $totalIn,
            totalOut: $totalOut,
            profit: $profit,
            declaredOpening: $this->declaredOpening($counterparty, $currency),
        );
    }

    /**
     * Which side of the relationship an entry moved value on.
     *
     * The four buckets sit on both sides of the balance sheet, so the same arithmetic
     * sign means opposite things depending on the bucket:
     *
     *   credit_trust up    — they handed money over          → in
     *   credit_trust down  — we paid some of it back         → out
     *   receivable up      — they took money and now owe it  → out
     *   receivable down    — they settled                    → in
     *
     * Increasing what we owe them, or reducing what they owe us, both mean value came
     * from them. That is the whole rule.
     *
     * @return array{Money|null, Money|null}
     */
    private function split(BalanceBucket $bucket, Money $signed): array
    {
        $increased = ! $signed->isNegative();
        $fromThem = $bucket->isLiability() === $increased;

        return $fromThem
            ? [$signed->absolute(), null]
            : [null, $signed->absolute()];
    }

    /**
     * Every ledger account this party holds in this currency, keyed by id.
     *
     * @return array<int, LedgerAccount>
     */
    private function accountsOf(Counterparty $counterparty, Currency $currency): array
    {
        /** @var array<int, LedgerAccount> $accounts */
        $accounts = LedgerAccount::query()
            ->where('owner_type', LedgerOwnerType::Counterparty->value)
            ->where('owner_id', $counterparty->getKey())
            ->where('currency_id', $currency->getKey())
            ->get()
            ->keyBy('id')
            ->all();

        return $accounts;
    }

    /**
     * @param  array<int, LedgerAccount>  $accounts
     * @return Collection<int, LedgerEntry>
     */
    private function entries(array $accounts, StatementMode $mode, ?Carbon $to): Collection
    {
        // Client mode never selects the profit columns. This is the enforcement point
        // for Section 9: the figures are absent from the result set, so they cannot
        // reach a prop, a page source, or a printed document by accident.
        $columns = ['id', 'type', 'status', 'occurred_at', 'reference', 'description', 'method'];

        if ($mode->showsProfit()) {
            $columns = [...$columns, 'net_profit', 'profit_currency_id', 'profit_method', 'profit_status'];
        }

        $until = $to?->copy()->endOfDay();

        return LedgerEntry::query()
            ->whereIn('ledger_account_id', array_keys($accounts))
            ->when($until !== null, fn (Builder $query): Builder => $query->where('occurred_at', '<=', $until))
            ->with(['transaction' => fn ($query) => $query->select($columns)])
            // Sequence then id, so the two legs of one deal always read in the order
            // they were posted rather than whichever the database returns first.
            ->orderBy('occurred_at')
            ->orderBy('transaction_id')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    /**
     * The margin on a deal, in whichever currency it was earned.
     *
     * Through the registry rather than a lookup per row: a statement can run to
     * hundreds of lines and they overwhelmingly share a handful of currencies.
     */
    private function profitOf(Transaction $transaction): ?Money
    {
        $amount = $transaction->net_profit;
        $currencyId = $transaction->profit_currency_id;

        if ($amount === null || $currencyId === null) {
            return null;
        }

        return Money::of($amount, $this->currencies->byId($currencyId));
    }

    /**
     * The buckets worth showing: any with a line, an opening balance or a closing one.
     *
     * A column per bucket regardless would print four columns of zeros for a party who
     * only ever left money on deposit. Showing only what is in play keeps the page
     * readable without ever combining two of them.
     *
     * @param  array<string, bool>  $used
     * @param  array<string, Money>  $opening
     * @param  array<string, Money>  $closing
     * @return list<BalanceBucket>
     */
    private function bucketsInPlay(array $used, array $opening, array $closing): array
    {
        $buckets = [];

        foreach (BalanceBucket::cases() as $bucket) {
            $inPlay = ($used[$bucket->value] ?? false)
                || ! $opening[$bucket->value]->isZero()
                || ! $closing[$bucket->value]->isZero();

            if ($inPlay) {
                $buckets[] = $bucket;
            }
        }

        return $buckets;
    }

    /**
     * Opening positions declared on the record but not posted to the ledger.
     *
     * Since opening positions started posting, this is almost always empty — a figure
     * typed on a counterparty now writes a transaction, and the transaction is in the
     * rows above. What is left here is the *unposted* remainder: positions declared
     * before that, which still owe the ledger an entry.
     *
     * @return array<string, Money>
     */
    private function declaredOpening(Counterparty $counterparty, Currency $currency): array
    {
        // One query for all four buckets. Asking per bucket was four queries to answer
        // a question that is almost always "none".
        $rows = $counterparty->openingBalances()
            ->where('currency_id', $currency->getKey())
            ->get();

        $declared = [];

        foreach (BalanceBucket::cases() as $bucket) {
            $row = $rows->firstWhere('bucket', $bucket);
            $amount = $row?->amount;

            if ($amount === null) {
                continue;
            }

            $outstanding = $amount->minus($row->posted_amount ?? $currency->zero());

            if (! $outstanding->isZero()) {
                $declared[$bucket->value] = $outstanding;
            }
        }

        return $declared;
    }
}
