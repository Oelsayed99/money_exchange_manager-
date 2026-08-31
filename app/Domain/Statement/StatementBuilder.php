<?php

declare(strict_types=1);

namespace App\Domain\Statement;

use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\Decimal;
use App\Domain\Money\Money;
use App\Enums\EntryDirection;
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
        $account = $this->accountOf($counterparty, $currency);

        $zero = $currency->zero();
        $running = $zero;
        $opening = $zero;
        $totalIn = $zero;
        $totalOut = $zero;

        $rows = [];
        $profit = [];
        $profitCounted = [];

        foreach ($this->entries($account, $mode, $to) as $entry) {
            // A debit on the client's account means value went to them; a credit means
            // it came from them. That is the whole sign convention, and it is why the
            // account is an asset: the balance then reads positive when they owe us.
            $signed = $entry->direction === EntryDirection::Debit ? $entry->amount : $entry->amount->negated();

            $running = $running->plus($signed);

            // Everything before the period is history: it sets the opening position and
            // is not listed.
            if ($from !== null && $entry->occurred_at->lt($from->copy()->startOfDay())) {
                $opening = $running;

                continue;
            }

            $out = $signed->isNegative() ? null : $signed;
            $in = $signed->isNegative() ? $signed->absolute() : null;

            $totalIn = $totalIn->plus($in ?? $zero);
            $totalOut = $totalOut->plus($out ?? $zero);

            $transaction = $entry->transaction;

            if (! $transaction instanceof Transaction) {
                throw new RuntimeException("Ledger entry #{$entry->id} has no transaction.");
            }

            // The margin belongs to the deal. A deal that touched this account twice
            // produces two lines, and showing the profit on both would report it twice.
            $rowProfit = null;

            if ($mode->showsProfit() && ! isset($profitCounted[$transaction->id])) {
                $profitCounted[$transaction->id] = true;
                $rowProfit = $this->profitOf($transaction);

                if ($rowProfit !== null && ! $rowProfit->isZero()) {
                    $code = $rowProfit->currency->code;
                    $profit[$code] = isset($profit[$code]) ? $profit[$code]->plus($rowProfit) : $rowProfit;
                }
            }

            [$moved, $rate] = $this->whatActuallyMoved($transaction, $currency);

            $rows[] = new StatementRow(
                transactionId: $transaction->id,
                type: $transaction->type,
                occurredAt: $entry->occurred_at,
                reference: $transaction->reference,
                description: $transaction->description,
                in: $in,
                out: $out,
                balanceAfter: $running,
                movedAmount: $moved,
                rate: $rate,
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
            totalIn: $totalIn,
            totalOut: $totalOut,
            profit: $profit,
            declaredOpening: $this->declaredOpening($counterparty, $currency),
        );
    }

    /**
     * The money that physically moved, when it was not in this statement's currency.
     *
     * Read from the transaction's own legs rather than recomputed: the leg in another
     * currency is what the operator entered, and the rate beside it is what they agreed.
     *
     * @return array{Money|null, string|null}
     */
    private function whatActuallyMoved(Transaction $transaction, Currency $currency): array
    {
        foreach ($transaction->legs as $leg) {
            if ($leg->currency_id !== $currency->getKey()) {
                return [
                    $leg->amount,
                    $transaction->customer_rate === null
                        ? null
                        : Decimal::trimTrailingZeros($transaction->customer_rate),
                ];
            }
        }

        return [null, null];
    }

    /** The party's running account in this currency. */
    private function accountOf(Counterparty $counterparty, Currency $currency): ?LedgerAccount
    {
        return LedgerAccount::query()
            ->where('owner_type', LedgerOwnerType::Counterparty->value)
            ->where('owner_id', $counterparty->getKey())
            ->where('currency_id', $currency->getKey())
            ->first();
    }

    /** @return Collection<int, LedgerEntry> */
    private function entries(?LedgerAccount $account, StatementMode $mode, ?Carbon $to): Collection
    {
        if (! $account instanceof LedgerAccount) {
            /** @var Collection<int, LedgerEntry> $empty */
            $empty = LedgerEntry::query()->whereRaw('1 = 0')->get();

            return $empty;
        }

        // Client mode never selects the profit columns. This is the enforcement point
        // for Section 9: the figures are absent from the result set, so they cannot
        // reach a prop, a page source, or a printed document by accident.
        $columns = ['id', 'type', 'status', 'occurred_at', 'reference', 'description', 'method', 'customer_rate'];

        if ($mode->showsProfit()) {
            $columns = [...$columns, 'net_profit', 'profit_currency_id', 'profit_method', 'profit_status'];
        }

        $until = $to?->copy()->endOfDay();

        return LedgerEntry::query()
            ->where('ledger_account_id', $account->getKey())
            ->when($until !== null, fn (Builder $query): Builder => $query->where('occurred_at', '<=', $until))
            ->with(['transaction' => fn ($query) => $query->select($columns)->with('legs')])
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
     * An opening figure declared on the record but not posted to the ledger.
     *
     * Almost always absent: declaring one writes a transaction, and the transaction is
     * in the rows above. What is left here is the unposted remainder.
     */
    private function declaredOpening(Counterparty $counterparty, Currency $currency): ?Money
    {
        $row = $counterparty->openingBalances()->where('currency_id', $currency->getKey())->first();

        if ($row === null || $row->amount === null) {
            return null;
        }

        $outstanding = $row->amount->minus($row->posted_amount ?? $currency->zero());

        return $outstanding->isZero() ? null : $outstanding;
    }
}
