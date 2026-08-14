<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ledger\BalanceProjector;
use App\Models\LedgerAccount;
use App\Models\LedgerBalance;
use Illuminate\Console\Command;

/**
 * Recompute every cached balance from the entries.
 *
 * Section 7: "A cached balance must be rebuildable from the ledger." Safe to run at
 * any time — it reads entries and overwrites the cache, and never touches an entry.
 */
final class LedgerRebuild extends Command
{
    protected $signature = 'ledger:rebuild';

    protected $description = 'Recompute all cached ledger balances from the entries themselves';

    public function handle(BalanceProjector $projector): int
    {
        $accounts = LedgerAccount::query()->orderBy('id')->get();

        if ($accounts->isEmpty()) {
            $this->info('No ledger accounts to rebuild.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($accounts->count());

        foreach ($accounts as $account) {
            $projected = $projector->project($account);

            LedgerBalance::query()->updateOrCreate(
                ['ledger_account_id' => $account->getKey()],
                [
                    'confirmed_amount' => $projected['confirmed'],
                    'pending_decrease_amount' => $projected['pending_decrease'],
                ],
            );

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Rebuilt {$accounts->count()} balances from the ledger.");

        return self::SUCCESS;
    }
}
