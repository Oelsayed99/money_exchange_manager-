<?php

declare(strict_types=1);

use App\Domain\Exchange\ExchangeInput;
use App\Domain\Exchange\ExchangeService;
use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Money\CurrencyRegistry;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\LedgerBalance;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Facades\DB;

/**
 * The one command that deletes recorded history.
 *
 * Everything it does is dangerous, so everything it does is asserted: that it removes
 * what it says, keeps what it says, and — the part that would go unnoticed — leaves the
 * append-only triggers exactly as it found them. A purge that silently left the ledger
 * editable would look like a success.
 */
beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->usd = Currency::query()->where('code', 'USD')->sole();

    $this->egpSafe = Account::factory()->create(['name' => 'EGP safe']);
    $this->usdSafe = Account::factory()->create(['name' => 'USD safe']);

    $this->client = Counterparty::factory()->create(['name' => 'Kept client']);
    $this->client->openingBalances()->create([
        'currency_id' => $this->egp->id,
        'amount' => '-899510',
        'posted_amount' => '0',
    ]);

    app(ExchangeService::class)->record(new ExchangeInput(
        receivedCurrency: $this->egp,
        receivedAmount: $this->egp->money('2574000'),
        receivedInto: $this->egpSafe,
        deliveredCurrency: $this->usd,
        deliveredAmount: $this->usd->money('50000'),
        deliveredFrom: $this->usdSafe,
        occurredAt: now(),
        costRate: '51.20',
    ));
});

/** @return list<string> */
function triggerNames(): array
{
    $names = array_map(
        fn (object $row): string => (string) $row->Trigger,
        DB::select('SHOW TRIGGERS'),
    );

    sort($names);

    return $names;
}

it('leaves nothing recorded behind', function (): void {
    expect(Transaction::count())->toBeGreaterThan(0)
        ->and(LedgerEntry::count())->toBeGreaterThan(0);

    $this->artisan('ledger:purge --force --skip-backup')->assertExitCode(0);

    expect(Transaction::count())->toBe(0)
        ->and(LedgerEntry::count())->toBe(0)
        ->and(LedgerBalance::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0)
        ->and(DB::table('transaction_legs')->count())->toBe(0);
});

it('keeps what the business is set up with', function (): void {
    $this->artisan('ledger:purge --force --skip-backup')->assertExitCode(0);

    expect(Currency::count())->toBeGreaterThan(0)
        ->and(Account::count())->toBe(2)
        ->and(Counterparty::query()->where('name', 'Kept client')->exists())->toBeTrue();
});

// Declared openings are figures somebody typed about a client, not a movement anyone
// recorded. Losing them to a command aimed at trial data would be the worst kind of
// surprise, so they need asking for.
it('keeps declared opening balances unless asked', function (): void {
    $this->artisan('ledger:purge --force --skip-backup')->assertExitCode(0);

    expect(DB::table('counterparty_opening_balances')->count())->toBe(1);
});

it('clears declared opening balances when asked', function (): void {
    $this->artisan('ledger:purge --force --skip-backup --openings')->assertExitCode(0);

    expect(DB::table('counterparty_opening_balances')->count())->toBe(0);
});

// The reason this command exists rather than a handful of statements at a prompt.
it('puts the append-only triggers back', function (): void {
    $before = triggerNames();

    $this->artisan('ledger:purge --force --skip-backup')->assertExitCode(0);

    expect(triggerNames())->toBe($before);
});

it('leaves the ledger append-only again afterwards', function (): void {
    $this->artisan('ledger:purge --force --skip-backup')->assertExitCode(0);

    app(ExchangeService::class)->record(new ExchangeInput(
        receivedCurrency: $this->egp,
        receivedAmount: $this->egp->money('1000'),
        receivedInto: $this->egpSafe,
        deliveredCurrency: $this->usd,
        deliveredAmount: $this->usd->money('20'),
        deliveredFrom: $this->usdSafe,
        occurredAt: now(),
        costRate: '50',
    ));

    expect(fn () => DB::table('ledger_entries')->delete())
        ->toThrow(Exception::class, 'append-only');
});

it('does nothing when the confirmation is declined', function (): void {
    $this->artisan('ledger:purge')
        ->expectsConfirmation('Delete everything above from ['.DB::connection()->getDatabaseName().']?', 'no')
        ->assertExitCode(0);

    expect(Transaction::count())->toBeGreaterThan(0);
});
