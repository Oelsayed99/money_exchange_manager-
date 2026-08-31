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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->resolver = app(LedgerAccountResolver::class);
    $this->rules = app(PostingRules::class);
    $this->posting = app(PostingService::class);

    $this->safe = Account::factory()->create();
    $this->party = Counterparty::factory()->create();
});

function deposit(string $amount): void
{
    $test = test();

    $test->posting->post($test->rules->build(new TransactionInput(
        type: TransactionType::In,
        currency: $test->egp,
        amount: $test->egp->money($amount),
        occurredAt: now(),
        account: $test->safe,
        counterparty: $test->party,
    )));
}

describe('ledger:verify', function (): void {
    it('passes on a clean ledger', function (): void {
        deposit('1000');
        deposit('500');

        $this->artisan('ledger:verify')
            ->expectsOutputToContain('every cached balance agrees')
            ->assertExitCode(0);
    });

    it('passes on an empty ledger', function (): void {
        $this->artisan('ledger:verify')->assertExitCode(0);
    });

    // The cache is disposable and the entries are not, so a disagreement means the
    // cache is wrong by definition.
    it('detects a tampered cache and exits non-zero', function (): void {
        deposit('1000');

        $credit = $this->resolver->forCounterparty($this->party, $this->egp);

        DB::table('ledger_balances')
            ->where('ledger_account_id', $credit->id)
            ->update(['confirmed_amount' => '999999.0000000000']);

        $this->artisan('ledger:verify')
            ->expectsOutputToContain('discrepancies found')
            ->assertExitCode(1);
    });

    it('names the account and both figures', function (): void {
        deposit('1000');

        $credit = $this->resolver->forCounterparty($this->party, $this->egp);

        DB::table('ledger_balances')->where('ledger_account_id', $credit->id)
            ->update(['confirmed_amount' => '7.0000000000']);

        // Output captured directly rather than through chained expectations, so the
        // assertion is unambiguous about what was actually printed.
        expect(Artisan::call('ledger:verify'))->toBe(1);

        $output = Artisan::output();

        expect($output)->toContain($credit->code)
            ->and($output)->toContain('7.00')
            ->and($output)->toContain('1000.00');
    });

    // The posting service enforces the invariant on the way in; this proves it still
    // holds in what is actually stored, which is a different and stronger claim.
    it('re-checks that every transaction balances', function (): void {
        deposit('1000');

        $this->artisan('ledger:verify --transactions')
            ->expectsOutputToContain('Every transaction balances')
            ->assertExitCode(0);
    });
});

describe('ledger:rebuild', function (): void {
    it('restores a tampered cache from the entries', function (): void {
        deposit('1000');
        deposit('500');

        $credit = $this->resolver->forCounterparty($this->party, $this->egp);

        DB::table('ledger_balances')->where('ledger_account_id', $credit->id)
            ->update(['confirmed_amount' => '0']);

        $this->artisan('ledger:rebuild')->assertExitCode(0);

        expect(LedgerBalance::query()->where('ledger_account_id', $credit->id)->sole()->confirmed()->toDisplayString())
            ->toBe('-1500.00');

        $this->artisan('ledger:verify')->assertExitCode(0);
    });

    it('rebuilds identically when nothing is wrong', function (): void {
        deposit('581000');
        deposit('436540');

        $before = DB::table('ledger_balances')->orderBy('ledger_account_id')->pluck('confirmed_amount')->all();

        $this->artisan('ledger:rebuild')->assertExitCode(0);

        expect(DB::table('ledger_balances')->orderBy('ledger_account_id')->pluck('confirmed_amount')->all())->toBe($before);
    });

    it('never touches an entry', function (): void {
        deposit('1000');

        $entries = DB::table('ledger_entries')->orderBy('id')->get()->toArray();

        $this->artisan('ledger:rebuild')->assertExitCode(0);

        expect(DB::table('ledger_entries')->orderBy('id')->get()->toArray())->toEqual($entries);
    });

    it('copes with an empty ledger', function (): void {
        $this->artisan('ledger:rebuild')
            ->expectsOutputToContain('No ledger accounts')
            ->assertExitCode(0);
    });

    // A reversed transaction keeps its entries and keeps counting; the reversing
    // entries are what cancel it. The projector has to agree with that, or a rebuild
    // would silently change every reversed balance.
    it('agrees with the posting service about reversals', function (): void {
        deposit('1000');
        $second = $this->posting->post($this->rules->build(new TransactionInput(
            type: TransactionType::In,
            currency: $this->egp,
            amount: $this->egp->money('400'),
            occurredAt: now(),
            account: $this->safe,
            counterparty: $this->party,
        )));

        $this->posting->reverse($second);

        $this->artisan('ledger:verify')->assertExitCode(0);
        $this->artisan('ledger:rebuild')->assertExitCode(0);
        $this->artisan('ledger:verify')->assertExitCode(0);

        $credit = $this->resolver->forCounterparty($this->party, $this->egp);

        expect(LedgerBalance::query()->where('ledger_account_id', $credit->id)->sole()->confirmed()->toDisplayString())
            ->toBe('-1000.00');
    });
});

describe('concurrency', function (): void {
    // Locks are taken in ascending ledger-account id order. Two postings touching the
    // same pair in opposite directions would deadlock without a fixed order. The live
    // two-connection test lives in tests/Integration, which does not hold an open
    // transaction the way this suite does.
    it('writes balances for exactly the accounts the entries touched', function (): void {
        deposit('1000');

        $touched = DB::table('ledger_entries')->distinct()->pluck('ledger_account_id')->sort()->values()->all();

        expect(DB::table('ledger_balances')->orderBy('ledger_account_id')->pluck('ledger_account_id')->all())
            ->toBe($touched);
    });
});
