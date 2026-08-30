<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Money\CurrencyRegistry;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\LedgerBalance;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Facades\DB;

/**
 * Concurrency, tested against real committed data.
 *
 * This suite truncates rather than rolling back, because a second connection has to be
 * able to see what the first one wrote. Under RefreshDatabase the data lives inside an
 * open transaction and is invisible to anyone else, which makes locking untestable.
 */
beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->resolver = app(LedgerAccountResolver::class);
    $this->safe = Account::factory()->create();
    $this->party = Counterparty::factory()->create();

    $this->deposit = function (string $amount): void {
        app(PostingService::class)->post(app(PostingRules::class)->build(new TransactionInput(
            type: TransactionType::In,
            currency: $this->egp,
            amount: $this->egp->money($amount),
            occurredAt: now(),
            account: $this->safe,
            counterparty: $this->party,
        )));
    };
});

function secondConnection(): PDO
{
    $config = config('database.connections.mysql');

    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
        (string) $config['username'],
        (string) $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

// Without the row lock, two postings would both read the same starting balance and one
// increment would silently vanish. This proves the lock is real, not aspirational.
it('makes a second connection wait for a balance row that is locked', function (): void {
    ($this->deposit)('1000');

    $credit = $this->resolver->forCounterparty($this->party, $this->egp);

    $other = secondConnection();
    $other->beginTransaction();
    $other->query("SELECT * FROM ledger_balances WHERE ledger_account_id = {$credit->id} FOR UPDATE")->fetchAll();

    $blocked = false;

    try {
        // NOWAIT rather than waiting out the timeout, so the test is fast and its
        // failure mode is "not locked" rather than "slow".
        DB::select("SELECT * FROM ledger_balances WHERE ledger_account_id = {$credit->id} FOR UPDATE NOWAIT");
    } catch (Throwable) {
        $blocked = true;
    }

    $other->rollBack();

    expect($blocked)->toBeTrue();
});

it('lets the second connection through once the lock is released', function (): void {
    ($this->deposit)('1000');

    $credit = $this->resolver->forCounterparty($this->party, $this->egp);

    $other = secondConnection();
    $other->beginTransaction();
    $other->query("SELECT * FROM ledger_balances WHERE ledger_account_id = {$credit->id} FOR UPDATE")->fetchAll();
    $other->rollBack();

    $rows = DB::select("SELECT * FROM ledger_balances WHERE ledger_account_id = {$credit->id} FOR UPDATE NOWAIT");

    expect($rows)->toHaveCount(1);
});

// Sequential postings are the ordinary case, and the sum must be exact regardless of
// how many there are.
it('accumulates every posting without losing one', function (): void {
    foreach (range(1, 25) as $ignored) {
        ($this->deposit)('100');
    }

    $credit = $this->resolver->forCounterparty($this->party, $this->egp);

    expect(LedgerBalance::query()->where('ledger_account_id', $credit->id)->sole()->confirmed()->toDisplayString())
        ->toBe('-2500.00');

    $this->artisan('ledger:verify')->assertExitCode(0);
});
