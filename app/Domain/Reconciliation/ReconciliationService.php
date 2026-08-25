<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation;

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\Decimal;
use App\Domain\Money\Money;
use App\Enums\EntryDirection;
use App\Enums\LedgerAccountSubkind;
use App\Enums\LedgerOwnerType;
use App\Enums\ReconciliationStatus;
use App\Models\Account;
use App\Models\Currency;
use App\Models\LedgerEntry;
use App\Models\Reconciliation;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recording and resolving reconciliations.
 *
 * ## Why the balance is recomputed rather than read from the cache
 *
 * `ledger_balances` holds what an account holds *now*. A reconciliation asks what it
 * held on a particular day, and those differ the moment anything is backdated — which
 * is normal: a deal done on Friday gets entered on Monday. So the figure is summed
 * from entries up to the close of that day.
 *
 * ## Why the ledger figure is then stored
 *
 * Having computed it, it is written down. It could be recomputed on every read, and
 * that is exactly the problem: a reconciliation saying "on 30 June the ledger held X"
 * would silently become "held Y" as soon as somebody posted a 15 June entry, and the
 * only evidence that anything had moved would be gone. Storing it means the drift is
 * visible — {@see drift} is what makes it visible.
 */
final class ReconciliationService
{
    public function __construct(
        private readonly LedgerAccountResolver $accounts,
        private readonly CurrencyRegistry $currencies,
    ) {}

    /**
     * What the ledger says an account held in one currency at the close of a day.
     *
     * Summed from entries, not read from the balance cache, which only knows about now.
     */
    public function ledgerBalanceAsOf(Account $account, Currency $currency, Carbon $asOf): Money
    {
        $ledgerAccount = $this->accounts->forAccount($account, $currency);

        $totals = LedgerEntry::query()
            ->where('ledger_account_id', $ledgerAccount->id)
            ->where('occurred_at', '<=', $asOf->copy()->endOfDay())
            ->groupBy('direction')
            ->select(['direction', DB::raw('SUM(amount) as total')])
            ->pluck('total', 'direction');

        $spec = $currency->spec();
        $balance = Money::zero($spec);

        foreach ($totals as $direction => $total) {
            $amount = Money::of((string) $total, $spec);

            // Cash is an asset: a debit increases it, a credit reduces it. Taken from
            // the account's own kind rather than assumed, so a reconciliation of
            // something that is not an asset would still read correctly.
            $balance = $ledgerAccount->kind->signFor(EntryDirection::from((string) $direction)) > 0
                ? $balance->plus($amount)
                : $balance->minus($amount);
        }

        return $balance;
    }

    /**
     * Record a count against the ledger.
     *
     * The difference is counted minus ledger: positive means more was found than the
     * ledger expected. Nothing is posted — see {@see ReconciliationStatus}.
     */
    public function record(
        Account $account,
        Currency $currency,
        Carbon $asOf,
        Money $counted,
        ?User $by = null,
        ?string $note = null,
    ): Reconciliation {
        if (! $counted->currency->is($currency->spec())) {
            throw new DomainException(
                "A count in {$counted->currency->code} cannot reconcile a {$currency->code} balance."
            );
        }

        if ($asOf->isFuture()) {
            throw new DomainException(
                'A reconciliation records a count that has happened. '.$asOf->toDateString().' has not.'
            );
        }

        $ledger = $this->ledgerBalanceAsOf($account, $currency, $asOf);
        $difference = $counted->minus($ledger);

        return Reconciliation::query()->create([
            'account_id' => $account->getKey(),
            'currency_id' => $currency->getKey(),
            'as_of' => $asOf->toDateString(),
            'counted_amount' => $counted->toStorageString(),
            'ledger_amount' => $ledger->toStorageString(),
            'difference' => $difference->toStorageString(),
            'status' => $difference->isZero()
                ? ReconciliationStatus::Balanced
                : ReconciliationStatus::Open,
            'note' => $note,
            'created_by' => $by?->getKey(),
        ]);
    }

    /**
     * Explain a difference.
     *
     * Explaining is not correcting. If the ledger was wrong it is corrected by posting
     * an adjustment, and that transaction is recorded here so the two are linked; if
     * the count was wrong, or the difference is a timing effect that will wash out,
     * the explanation stands on its own.
     */
    public function resolve(
        Reconciliation $reconciliation,
        string $resolution,
        ?User $by = null,
        ?int $adjustmentTransactionId = null,
    ): Reconciliation {
        if ($reconciliation->isBalanced()) {
            throw new DomainException(
                'This reconciliation balanced. There is no difference to explain.'
            );
        }

        if (trim($resolution) === '') {
            throw new DomainException(
                'A resolution has to say something. An unexplained difference is better left open '
                .'than closed with a blank.'
            );
        }

        $reconciliation->update([
            'status' => ReconciliationStatus::Resolved,
            'resolution' => $resolution,
            'resolved_by' => $by?->getKey(),
            'resolved_at' => now(),
            'adjustment_transaction_id' => $adjustmentTransactionId,
        ]);

        return $reconciliation->refresh();
    }

    /**
     * Drift for many reconciliations at once.
     *
     * {@see drift} answers for one, which is one query, which on a list of two hundred
     * is two hundred queries. This answers for all of them in one, by joining each row
     * to its own cash account and summing the entries dated on or before its own day.
     *
     * The direction arithmetic is spelled out in SQL here and read from the account's
     * kind in the single-row version, which is a duplication worth watching — so a test
     * asserts the two agree for every row it is given.
     *
     * @param  Collection<int, Reconciliation>  $reconciliations
     * @return array<int, Money> keyed by reconciliation id; absent means no drift
     */
    public function driftFor(Collection $reconciliations): array
    {
        if ($reconciliations->isEmpty()) {
            return [];
        }

        $rows = DB::table('reconciliations as r')
            ->join('ledger_accounts as a', function ($join): void {
                $join->on('a.owner_id', '=', 'r.account_id')
                    ->on('a.currency_id', '=', 'r.currency_id')
                    ->where('a.owner_type', LedgerOwnerType::Account->value)
                    ->where('a.subkind', LedgerAccountSubkind::Cash->value);
            })
            ->leftJoin('ledger_entries as e', function ($join): void {
                // Everything up to the close of the day being reconciled. Expressed as
                // "before the next day" so it does not depend on the precision of a
                // timestamp.
                $join->on('e.ledger_account_id', '=', 'a.id')
                    ->whereRaw('e.occurred_at < DATE_ADD(r.as_of, INTERVAL 1 DAY)');
            })
            ->whereIn('r.id', $reconciliations->pluck('id')->all())
            ->groupBy('r.id', 'r.currency_id', 'r.ledger_amount')
            ->select([
                'r.id',
                'r.currency_id',
                'r.ledger_amount',
                // Cash is an asset: a debit increases it, a credit reduces it.
                DB::raw("COALESCE(SUM(CASE WHEN e.direction = 'debit' THEN e.amount ELSE -e.amount END), 0) as balance_now"),
            ])
            ->get();

        $drift = [];

        foreach ($rows as $row) {
            $spec = $this->currencies->byId((int) $row->currency_id);

            $now = Money::of(Decimal::truncateTo((string) $row->balance_now, Money::SCALE), $spec);
            $recorded = Money::of((string) $row->ledger_amount, $spec);

            $difference = $now->minus($recorded);

            if (! $difference->isZero()) {
                $drift[(int) $row->id] = $difference;
            }
        }

        return $drift;
    }

    /**
     * How far the ledger has moved since this reconciliation was taken.
     *
     * Non-zero means an entry dated on or before `as_of` was posted after the count —
     * a backdated transaction. That is not an error in itself, and it is worth seeing:
     * it means a reconciliation somebody signed off no longer describes the ledger.
     */
    public function drift(Reconciliation $reconciliation): Money
    {
        $account = $reconciliation->account;
        $currency = $reconciliation->currency;

        if ($account === null || $currency === null) {
            throw new DomainException("Reconciliation #{$reconciliation->id} has lost its account or currency.");
        }

        $now = $this->ledgerBalanceAsOf($account, $currency, $reconciliation->as_of);

        return $now->minus($reconciliation->ledger_amount);
    }
}
