<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Money\Money;
use App\Domain\Tenancy\ScopedQuery;
use App\Enums\EntryDirection;
use App\Enums\TransactionStatus;
use App\Models\LedgerAccount;

/**
 * Computes balances from ledger entries alone, ignoring the cache entirely.
 *
 * The definition of what a balance *is*. `ledger:rebuild` overwrites the cache with
 * this; `ledger:verify` compares the two. If they disagree, the cache is wrong — it is
 * disposable, and the entries are not.
 */
final class BalanceProjector
{
    public function __construct(private readonly ScopedQuery $scoped) {}

    /**
     * @return array{confirmed: string, pending_decrease: string}
     */
    public function project(LedgerAccount $account): array
    {
        $spec = $account->spec();
        $sign = fn (EntryDirection $direction): int => $account->kind->signFor($direction);

        $confirmed = Money::zero($spec);
        $pendingDecrease = Money::zero($spec);

        // Chunked rather than loaded whole: an account with years of history should not
        // need to fit in memory to be verified.
        $this->scoped->table('ledger_entries')
            ->join('transactions', 'transactions.id', '=', 'ledger_entries.transaction_id')
            ->where('ledger_entries.ledger_account_id', $account->getKey())
            ->select(['ledger_entries.direction', 'ledger_entries.amount', 'transactions.status'])
            ->orderBy('ledger_entries.id')
            ->chunk(1000, function ($rows) use (&$confirmed, &$pendingDecrease, $spec, $sign): void {
                foreach ($rows as $row) {
                    $direction = EntryDirection::from((string) $row->direction);
                    $status = TransactionStatus::from((string) $row->status);

                    $amount = Money::of((string) $row->amount, $spec);
                    $signed = $sign($direction) === 1 ? $amount : $amount->negated();

                    if ($status->isConfirmed()) {
                        $confirmed = $confirmed->plus($signed);

                        continue;
                    }

                    if ($status === TransactionStatus::Pending && $signed->isNegative()) {
                        $pendingDecrease = $pendingDecrease->plus($signed->absolute());
                    }
                }
            });

        return [
            'confirmed' => $confirmed->toStorageString(),
            'pending_decrease' => $pendingDecrease->toStorageString(),
        ];
    }
}
