<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\Money;
use App\Enums\BalanceBucket;
use App\Enums\CounterpartyStatus;
use App\Enums\EntryDirection;
use App\Enums\LedgerAccountSubkind;
use App\Enums\LedgerOwnerType;
use App\Enums\TransactionStatus;
use App\Models\Counterparty;
use App\Models\Currency;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's figures.
 *
 * ## Aggregated in SQL, and why that is safe here
 *
 * Amounts are `DECIMAL(28,10)` and MySQL's decimal arithmetic is exact, so `SUM` over
 * them loses nothing — unlike a float column, where it would. The results come back as
 * strings and go straight into `Money`. Nothing is cast to a float at any point.
 *
 * ## Positions are now; activity is the period
 *
 * A date filter narrows what *happened* — money in, money out, margin earned. It does
 * not move the positions, which are read from the balance cache as they stand today.
 * "Who owes me" is a question about now; asking it of last month would answer a
 * question nobody on this screen is asking.
 */
final class DashboardQuery
{
    public function __construct(private readonly CurrencyRegistry $currencies) {}

    public function run(DashboardFilters $filters): Dashboard
    {
        $positions = $this->positions($filters);
        $activity = $this->activity($filters);
        $profit = $this->profit($filters);
        $cash = $this->cashOnHand($filters);

        [$owedToUs, $owedToThem] = $this->totalPositions($positions);
        $parties = $this->parties($positions, $filters);

        return new Dashboard(
            cashOnHand: $cash,
            owedToUs: $owedToUs,
            owedToThem: $owedToThem,
            receivedFromParties: $activity['in'],
            deliveredToParties: $activity['out'],
            profit: $profit,
            counterparties: $parties,
            monthlyProfit: $filters->currency !== null ? $this->monthlyProfit($filters) : [],
            currencies: $this->currencyOrder([$cash, $owedToUs, $owedToThem, $activity['in'], $activity['out'], $profit]),
        );
    }

    /**
     * Every counterparty position, from the balance cache.
     *
     * @return list<array{owner_id: int, currency_id: int, subkind: string, amount: string}>
     */
    private function positions(DashboardFilters $filters): array
    {
        $rows = $this->counterpartyAccounts($filters)
            ->join('ledger_balances', 'ledger_balances.ledger_account_id', '=', 'ledger_accounts.id')
            ->select([
                'ledger_accounts.owner_id',
                'ledger_accounts.currency_id',
                'ledger_accounts.subkind',
                'ledger_balances.confirmed_amount as amount',
            ])
            ->get();

        $positions = [];

        foreach ($rows as $row) {
            $positions[] = [
                'owner_id' => (int) $row->owner_id,
                'currency_id' => (int) $row->currency_id,
                'subkind' => (string) $row->subkind,
                'amount' => (string) $row->amount,
            ];
        }

        return $positions;
    }

    /**
     * Money in and out across counterparty accounts during the period.
     *
     * Grouped in the database down to (currency, bucket, direction) — a handful of
     * rows however many entries there are — and turned into in/out here, where the
     * rule that a loan out increases an asset can be written once and read.
     *
     * @return array{in: array<string, Money>, out: array<string, Money>}
     */
    private function activity(DashboardFilters $filters): array
    {
        $rows = $this->counterpartyAccounts($filters)
            ->join('ledger_entries', 'ledger_entries.ledger_account_id', '=', 'ledger_accounts.id')
            ->when($filters->since() !== null, fn (Builder $q) => $q->where('ledger_entries.occurred_at', '>=', $filters->since()))
            ->when($filters->until() !== null, fn (Builder $q) => $q->where('ledger_entries.occurred_at', '<=', $filters->until()))
            ->groupBy('ledger_accounts.currency_id', 'ledger_accounts.subkind', 'ledger_entries.direction')
            ->select([
                'ledger_accounts.currency_id',
                'ledger_accounts.subkind',
                'ledger_entries.direction',
                DB::raw('SUM(ledger_entries.amount) as total'),
            ])
            ->get();

        $in = [];
        $out = [];

        foreach ($rows as $row) {
            $subkind = LedgerAccountSubkind::from((string) $row->subkind);
            $bucket = $subkind->bucket();

            if ($bucket === null) {
                continue;
            }

            $spec = $this->currencies->byId((int) $row->currency_id);
            $amount = Money::of((string) $row->total, $spec);

            $increased = $subkind->kind()->signFor(EntryDirection::from((string) $row->direction)) > 0;

            // The statement's rule, unchanged: increasing what we owe them, or reducing
            // what they owe us, both mean value came from them.
            $fromThem = $bucket->isLiability() === $increased;

            if ($fromThem) {
                $in[$spec->code] = isset($in[$spec->code]) ? $in[$spec->code]->plus($amount) : $amount;
            } else {
                $out[$spec->code] = isset($out[$spec->code]) ? $out[$spec->code]->plus($amount) : $amount;
            }
        }

        return ['in' => $in, 'out' => $out];
    }

    /**
     * Margin recognised in the period, per currency it was earned in.
     *
     * Never converted and summed. A total across currencies would need a rate, and the
     * headline figure would then move when the market did.
     *
     * @return array<string, Money>
     */
    private function profit(DashboardFilters $filters): array
    {
        $rows = $this->postedTransactions($filters)
            ->groupBy('profit_currency_id')
            ->select(['profit_currency_id', DB::raw('SUM(net_profit) as total')])
            ->get();

        $profit = [];

        foreach ($rows as $row) {
            $spec = $this->currencies->byId((int) $row->profit_currency_id);
            $profit[$spec->code] = Money::of((string) $row->total, $spec);
        }

        return $profit;
    }

    /**
     * Margin month by month, for the chart.
     *
     * Only when a single currency is chosen. Plotting several currencies on one axis
     * would compare figures that have no common scale — the base-currency mistake,
     * drawn instead of written.
     *
     * @return array<string, string>
     */
    private function monthlyProfit(DashboardFilters $filters): array
    {
        $rows = $this->postedTransactions($filters)
            ->groupBy(DB::raw("DATE_FORMAT(occurred_at, '%Y-%m')"))
            ->orderBy(DB::raw("DATE_FORMAT(occurred_at, '%Y-%m')"))
            ->select([
                DB::raw("DATE_FORMAT(occurred_at, '%Y-%m') as month"),
                DB::raw('SUM(net_profit) as total'),
            ])
            ->get();

        // Only reached with a currency chosen; the guard in run() is the one that
        // decides, and this method has no meaning without it.
        if ($filters->currency === null) {
            return [];
        }

        $spec = $filters->currency->spec();
        $monthly = [];

        foreach ($rows as $row) {
            $monthly[(string) $row->month] = Money::of((string) $row->total, $spec)->toStorageString();
        }

        return $monthly;
    }

    /**
     * What sits in our own custody locations.
     *
     * Deliberately not narrowed by the counterparty filter: the cash in the safe is not
     * anybody's in particular, and filtering it by client would produce a figure that
     * looks like an answer to a question nobody asked.
     *
     * @return array<string, Money>
     */
    private function cashOnHand(DashboardFilters $filters): array
    {
        $rows = DB::table('ledger_accounts')
            ->join('ledger_balances', 'ledger_balances.ledger_account_id', '=', 'ledger_accounts.id')
            ->where('ledger_accounts.subkind', LedgerAccountSubkind::Cash->value)
            ->when(
                $filters->currency !== null,
                fn (Builder $q) => $q->where('ledger_accounts.currency_id', $filters->currency?->getKey()),
            )
            ->groupBy('ledger_accounts.currency_id')
            ->select(['ledger_accounts.currency_id', DB::raw('SUM(ledger_balances.confirmed_amount) as total')])
            ->get();

        $cash = [];

        foreach ($rows as $row) {
            $spec = $this->currencies->byId((int) $row->currency_id);
            $cash[$spec->code] = Money::of((string) $row->total, $spec);
        }

        return $cash;
    }

    /**
     * @param  list<array{owner_id: int, currency_id: int, subkind: string, amount: string}>  $positions
     * @return array{array<string, Money>, array<string, Money>}
     */
    private function totalPositions(array $positions): array
    {
        $owedToUs = [];
        $owedToThem = [];

        foreach ($positions as $row) {
            $bucket = LedgerAccountSubkind::from($row['subkind'])->bucket();

            if ($bucket === null) {
                continue;
            }

            $spec = $this->currencies->byId($row['currency_id']);
            $amount = Money::of($row['amount'], $spec);

            if ($amount->isZero()) {
                continue;
            }

            if ($bucket->isAsset()) {
                $owedToUs[$spec->code] = isset($owedToUs[$spec->code]) ? $owedToUs[$spec->code]->plus($amount) : $amount;
            } else {
                $owedToThem[$spec->code] = isset($owedToThem[$spec->code]) ? $owedToThem[$spec->code]->plus($amount) : $amount;
            }
        }

        return [$owedToUs, $owedToThem];
    }

    /**
     * The counterparty list, with a status per currency and one across them.
     *
     * @param  list<array{owner_id: int, currency_id: int, subkind: string, amount: string}>  $positions
     * @return list<CounterpartyPosition>
     */
    private function parties(array $positions, DashboardFilters $filters): array
    {
        /** @var array<int, array<string, array<string, Money>>> $byParty */
        $byParty = [];

        foreach ($positions as $row) {
            $bucket = LedgerAccountSubkind::from($row['subkind'])->bucket();

            if ($bucket === null) {
                continue;
            }

            $spec = $this->currencies->byId($row['currency_id']);
            $amount = Money::of($row['amount'], $spec);

            if ($amount->isZero()) {
                continue;
            }

            $byParty[$row['owner_id']][$spec->code][$bucket->value] = $amount;
        }

        $names = Counterparty::query()
            ->whereIn('id', array_keys($byParty))
            ->pluck('name', 'id');

        $parties = [];

        foreach ($byParty as $id => $currencies) {
            ksort($currencies);

            $statusByCurrency = [];

            foreach ($currencies as $code => $buckets) {
                $statusByCurrency[$code] = CounterpartyStatus::forSides(
                    $this->holds($buckets, BalanceBucket::Receivable, BalanceBucket::Custody),
                    $this->holds($buckets, BalanceBucket::Payable, BalanceBucket::CreditTrust),
                );
            }

            $overall = CounterpartyStatus::across(array_values($statusByCurrency));

            // Applied here rather than in SQL: a status is a reading of four positions,
            // not a column, so there is nothing to put in a WHERE clause.
            if ($filters->status !== null && $overall !== $filters->status) {
                continue;
            }

            $parties[] = new CounterpartyPosition(
                id: $id,
                name: (string) ($names[$id] ?? ''),
                status: $overall,
                positions: $currencies,
                statusByCurrency: $statusByCurrency,
            );
        }

        usort($parties, fn (CounterpartyPosition $a, CounterpartyPosition $b): int => strcmp($a->name, $b->name));

        return $parties;
    }

    /** @param array<string, Money> $buckets */
    private function holds(array $buckets, BalanceBucket ...$wanted): bool
    {
        foreach ($wanted as $bucket) {
            if (isset($buckets[$bucket->value]) && ! $buckets[$bucket->value]->isZero()) {
                return true;
            }
        }

        return false;
    }

    /** Counterparty-owned ledger accounts, narrowed by whichever filters apply. */
    private function counterpartyAccounts(DashboardFilters $filters): BuilderContract
    {
        return DB::table('ledger_accounts')
            ->where('ledger_accounts.owner_type', LedgerOwnerType::Counterparty->value)
            ->when(
                $filters->counterparty !== null,
                fn (Builder $q) => $q->where('ledger_accounts.owner_id', $filters->counterparty?->getKey()),
            )
            ->when(
                $filters->currency !== null,
                fn (Builder $q) => $q->where('ledger_accounts.currency_id', $filters->currency?->getKey()),
            );
    }

    /** Posted transactions carrying a margin, narrowed by whichever filters apply. */
    private function postedTransactions(DashboardFilters $filters): BuilderContract
    {
        return DB::table('transactions')
            ->where('status', TransactionStatus::Posted->value)
            ->whereNotNull('profit_currency_id')
            ->when(
                $filters->counterparty !== null,
                fn (Builder $q) => $q->where('counterparty_id', $filters->counterparty?->getKey()),
            )
            ->when(
                $filters->currency !== null,
                fn (Builder $q) => $q->where('profit_currency_id', $filters->currency?->getKey()),
            )
            ->when($filters->since() !== null, fn (Builder $q) => $q->where('occurred_at', '>=', $filters->since()))
            ->when($filters->until() !== null, fn (Builder $q) => $q->where('occurred_at', '<=', $filters->until()));
    }

    /**
     * Every currency mentioned anywhere, in the order currencies are configured.
     *
     * @param  list<array<string, Money>>  $groups
     * @return list<string>
     */
    private function currencyOrder(array $groups): array
    {
        $codes = [];

        foreach ($groups as $group) {
            foreach (array_keys($group) as $code) {
                $codes[$code] = true;
            }
        }

        $ordered = array_values(array_intersect(
            Currency::query()->orderBy('sort_order')->pluck('code')->all(),
            array_keys($codes),
        ));

        return $ordered;
    }
}
