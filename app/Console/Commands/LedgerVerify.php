<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ledger\BalanceProjector;
use App\Domain\Money\Money;
use App\Models\LedgerAccount;
use App\Models\LedgerBalance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Compare cached balances against the entries, and report every disagreement.
 *
 * Changes nothing. Exits non-zero when anything is out of step, so it can be run on a
 * schedule and actually noticed. Section 7 asks for exactly this as part of the
 * reconciliation workflow.
 */
final class LedgerVerify extends Command
{
    protected $signature = 'ledger:verify {--transactions : Also re-check that every transaction balances per currency}';

    protected $description = 'Check cached balances against the ledger, and report any that disagree';

    public function handle(BalanceProjector $projector): int
    {
        $problems = 0;

        $problems += $this->verifyBalances($projector);

        if ($this->option('transactions')) {
            $problems += $this->verifyTransactions();
        }

        if ($problems === 0) {
            $this->info('Ledger verified: every cached balance agrees with the entries.');

            return self::SUCCESS;
        }

        $this->error("{$problems} discrepancies found. The cache is wrong by definition — run ledger:rebuild.");

        return self::FAILURE;
    }

    private function verifyBalances(BalanceProjector $projector): int
    {
        $rows = [];

        foreach (LedgerAccount::query()->orderBy('id')->get() as $account) {
            $projected = $projector->project($account);
            $cached = LedgerBalance::query()->where('ledger_account_id', $account->getKey())->first();

            // A missing cache row means nothing has been posted to the account yet,
            // which is a balance of zero rather than an error.
            $cachedConfirmed = $cached === null ? '0' : $cached->confirmed_amount;
            $spec = $account->spec();

            if (Money::of($cachedConfirmed, $spec)->equals(Money::of($projected['confirmed'], $spec))) {
                continue;
            }

            $rows[] = [
                $account->code,
                Money::of($cachedConfirmed, $spec)->toDisplayString(),
                Money::of($projected['confirmed'], $spec)->toDisplayString(),
            ];
        }

        if ($rows !== []) {
            $this->newLine();
            $this->table(['Account', 'Cached', 'Ledger says'], $rows);
        }

        return count($rows);
    }

    /**
     * Re-check the central invariant against what is actually stored.
     *
     * The posting service enforces it on the way in; this proves it still holds, which
     * is a different and stronger claim.
     */
    private function verifyTransactions(): int
    {
        $unbalanced = DB::table('ledger_entries')
            ->select('transaction_id', 'currency_id')
            ->selectRaw("SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END) AS difference")
            ->groupBy('transaction_id', 'currency_id')
            ->havingRaw('difference <> 0')
            ->get();

        if ($unbalanced->isEmpty()) {
            $this->info('Every transaction balances in every currency it touches.');

            return 0;
        }

        $this->newLine();
        $this->table(
            ['Transaction', 'Currency', 'Difference'],
            $unbalanced->map(fn ($row): array => [
                (string) $row->transaction_id,
                (string) $row->currency_id,
                (string) $row->difference,
            ])->all(),
        );

        return $unbalanced->count();
    }
}
