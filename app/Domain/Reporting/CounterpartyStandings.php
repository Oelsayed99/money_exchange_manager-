<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\Money;
use App\Domain\Tenancy\ScopedQuery;
use App\Enums\LedgerAccountSubkind;
use App\Enums\LedgerOwnerType;

/**
 * Where every counterparty stands, from the ledger.
 *
 * One query for the whole list. The alternative — asking per party — is the shape that
 * turned `/transactions` into fifty-eight queries once (ADR 0022), and a counterparty
 * list is exactly where that would happen again.
 *
 * Read from the balance cache rather than summed from entries, which is what the cache
 * is for; `ledger:verify` is what says the two agree.
 */
final readonly class CounterpartyStandings
{
    public function __construct(
        private CurrencyRegistry $currencies,
        private ScopedQuery $scoped,
    ) {}

    /**
     * @param  list<int>  $counterpartyIds
     * @return array<int, list<CounterpartyStanding>> keyed by counterparty, currency-ordered
     */
    public function forParties(array $counterpartyIds): array
    {
        if ($counterpartyIds === []) {
            return [];
        }

        // The query builder rather than the model: this is a joined read model, and an
        // Eloquent row would apply the model's casts to columns that are not its own.
        $rows = $this->scoped->table('ledger_accounts')
            ->join('ledger_balances', 'ledger_balances.ledger_account_id', '=', 'ledger_accounts.id')
            ->where('ledger_accounts.owner_type', LedgerOwnerType::Counterparty->value)
            ->whereIn('ledger_accounts.owner_id', $counterpartyIds)
            ->select([
                'ledger_accounts.owner_id',
                'ledger_accounts.currency_id',
                'ledger_accounts.subkind',
                'ledger_balances.confirmed_amount as amount',
            ])
            ->get();

        /** @var array<int, list<CounterpartyStanding>> $standings */
        $standings = [];

        /** @var array<int, array<string, Money>> $byParty */
        $byParty = [];

        foreach ($rows as $row) {
            if (! LedgerAccountSubkind::from((string) $row->subkind)->isCounterpartyPosition()) {
                continue;
            }

            $spec = $this->currencies->byId((int) $row->currency_id);
            $amount = Money::of((string) $row->amount, $spec);

            // A settled position is not the same as no relationship, but on a list it
            // reads as noise. The statement is where a zero still says "square".
            if ($amount->isZero()) {
                continue;
            }

            $byParty[(int) $row->owner_id][$spec->code] = $amount;
        }

        foreach ($byParty as $id => $currencies) {
            ksort($currencies);

            foreach ($currencies as $code => $balance) {
                $standings[$id][] = new CounterpartyStanding($code, $balance);
            }
        }

        return $standings;
    }
}
