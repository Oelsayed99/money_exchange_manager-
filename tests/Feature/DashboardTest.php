<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Money\CurrencyRegistry;
use App\Domain\Reporting\DashboardFilters;
use App\Domain\Reporting\DashboardQuery;
use App\Enums\CounterpartyStatus;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->usd = Currency::query()->where('code', 'USD')->sole();

    $this->safe = Account::factory()->create(['name' => 'Main safe']);
    $this->rules = app(PostingRules::class);
    $this->posting = app(PostingService::class);
    $this->query = app(DashboardQuery::class);

    $this->operator = User::factory()->create();
    $this->operator->assignRole(Role::Operator->value);
});

function party(string $name): Counterparty
{
    return Counterparty::factory()->create(['name' => $name]);
}

function movement(
    TransactionType $type,
    Counterparty $party,
    string $amount,
    ?Currency $currency = null,
    string $date = '2026-06-10',
): Transaction {
    $test = test();

    return $test->posting->post($test->rules->build(new TransactionInput(
        type: $type,
        currency: $currency ?? $test->egp,
        amount: ($currency ?? $test->egp)->money($amount),
        occurredAt: new DateTimeImmutable($date),
        account: $test->safe,
        counterparty: $party,
    )));
}

function dashboard(?DashboardFilters $filters = null)
{
    return test()->query->run($filters ?? new DashboardFilters);
}

describe('status', function (): void {
    it('reads a party holding our money as owing us', function (): void {
        movement(TransactionType::LoanGiven, party('Owes'), '400000');

        expect(dashboard()->counterparties[0]->status)->toBe(CounterpartyStatus::OwesUs);
    });

    it('reads a party whose money we hold as having credit', function (): void {
        movement(TransactionType::CreditDeposit, party('Credit'), '500000');

        expect(dashboard()->counterparties[0]->status)->toBe(CounterpartyStatus::HasCredit);
    });

    // The case a single signed column cannot express, and the reason the four buckets
    // exist. 1,000,000 held and 400,000 owed is not 600,000 of anything.
    it('reads a party on both sides as both', function (): void {
        $both = party('Both');
        movement(TransactionType::CreditDeposit, $both, '1000000');
        movement(TransactionType::LoanGiven, $both, '400000');

        $row = dashboard()->counterparties[0];

        expect($row->status)->toBe(CounterpartyStatus::Mixed)
            ->and($row->positions['EGP']['credit_trust']->toDisplayString())->toBe('1000000.00')
            ->and($row->positions['EGP']['receivable']->toDisplayString())->toBe('400000.00');
    });

    // Owing in one currency while holding credit in another is genuinely both, and
    // resolves once a currency is chosen.
    it('reads disagreeing currencies as both, and resolves them on filtering', function (): void {
        $split = party('Split');
        movement(TransactionType::CreditDeposit, $split, '500000');
        movement(TransactionType::LoanGiven, $split, '10000', $this->usd);

        expect(dashboard()->counterparties[0]->status)->toBe(CounterpartyStatus::Mixed);

        $egpOnly = dashboard(new DashboardFilters(currency: $this->egp));

        expect($egpOnly->counterparties[0]->status)->toBe(CounterpartyStatus::HasCredit);
    });

    it('drops a party once everything is squared off', function (): void {
        $settled = party('Settled');
        movement(TransactionType::CreditDeposit, $settled, '500000');
        movement(TransactionType::CreditSettlement, $settled, '500000');

        expect(dashboard()->counterparties)->toBe([]);
    });
});

describe('filtering', function (): void {
    beforeEach(function (): void {
        movement(TransactionType::CreditDeposit, party('Holder'), '500000');
        movement(TransactionType::LoanGiven, party('Borrower'), '400000');
    });

    it('narrows to one status', function (): void {
        $owing = dashboard(new DashboardFilters(status: CounterpartyStatus::OwesUs));

        expect($owing->counterparties)->toHaveCount(1)
            ->and($owing->counterparties[0]->name)->toBe('Borrower');
    });

    it('narrows to one client', function (): void {
        $holder = Counterparty::query()->where('name', 'Holder')->sole();

        expect(dashboard(new DashboardFilters(counterparty: $holder))->counterparties)->toHaveCount(1);
    });

    it('lists everyone when nothing is chosen', function (): void {
        expect(dashboard()->counterparties)->toHaveCount(2);
    });
});

describe('the figures', function (): void {
    it('totals what is owed each way without netting them', function (): void {
        movement(TransactionType::CreditDeposit, party('Holder'), '1000000');
        movement(TransactionType::LoanGiven, party('Borrower'), '400000');

        $dashboard = dashboard();

        expect($dashboard->owedToThem['EGP']->toDisplayString())->toBe('1000000.00')
            ->and($dashboard->owedToUs['EGP']->toDisplayString())->toBe('400000.00');
    });

    it('counts what came in and what went out over the period', function (): void {
        $holder = party('Holder');
        movement(TransactionType::CreditDeposit, $holder, '1000000', null, '2026-06-01');
        movement(TransactionType::CreditSettlement, $holder, '250000', null, '2026-06-20');

        $dashboard = dashboard();

        expect($dashboard->receivedFromParties['EGP']->toDisplayString())->toBe('1000000.00')
            ->and($dashboard->deliveredToParties['EGP']->toDisplayString())->toBe('250000.00');
    });

    // The dates narrow what moved. They do not move the positions, which are a
    // question about now.
    it('narrows activity by date but leaves positions alone', function (): void {
        $holder = party('Holder');
        movement(TransactionType::CreditDeposit, $holder, '1000000', null, '2026-05-01');
        movement(TransactionType::CreditDeposit, $holder, '500000', null, '2026-06-01');

        $june = dashboard(new DashboardFilters(from: Carbon::parse('2026-06-01')));

        expect($june->receivedFromParties['EGP']->toDisplayString())->toBe('500000.00')
            ->and($june->owedToThem['EGP']->toDisplayString())->toBe('1500000.00');
    });

    it('reports cash in our own safes', function (): void {
        movement(TransactionType::CreditDeposit, party('Holder'), '1000000');

        expect(dashboard()->cashOnHand['EGP']->toDisplayString())->toBe('1000000.00');
    });

    // A client filter narrows the relationship figures. The cash in the safe is not
    // anybody's in particular, so it stays whole.
    it('leaves cash on hand alone when a client is chosen', function (): void {
        movement(TransactionType::CreditDeposit, party('A'), '1000000');
        movement(TransactionType::CreditDeposit, party('B'), '600000');

        $onlyA = dashboard(new DashboardFilters(counterparty: Counterparty::query()->where('name', 'A')->sole()));

        expect($onlyA->cashOnHand['EGP']->toDisplayString())->toBe('1600000.00')
            ->and($onlyA->owedToThem['EGP']->toDisplayString())->toBe('1000000.00');
    });
});

describe('margin', function (): void {
    beforeEach(function (): void {
        $this->party = party('Trader');
        movement(TransactionType::CreditDeposit, $this->party, '1000000', null, '2026-06-10');

        Transaction::query()->update([
            'net_profit' => '14000.0000000000',
            'profit_currency_id' => $this->egp->id,
            'counterparty_id' => $this->party->id,
        ]);
    });

    it('totals the margin in the currency it was earned in', function (): void {
        expect(dashboard()->profit['EGP']->toDisplayString())->toBe('14000.00');
    });

    // No base currency, so no combined figure. Three currencies means three numbers.
    it('never adds margin across currencies', function (): void {
        $usdDeal = movement(TransactionType::CreditDeposit, party('Other'), '5000', $this->usd);
        $usdDeal->update(['net_profit' => '300.0000000000', 'profit_currency_id' => $this->usd->id]);

        $profit = dashboard()->profit;

        expect(array_keys($profit))->toEqualCanonicalizing(['EGP', 'USD'])
            ->and($profit['EGP']->toDisplayString())->toBe('14000.00')
            ->and($profit['USD']->toDisplayString())->toBe('300.00');
    });

    it('breaks the margin down by month once a currency is chosen', function (): void {
        expect(dashboard(new DashboardFilters(currency: $this->egp))->monthlyProfit)
            ->toBe(['2026-06' => '14000.0000000000']);
    });

    it('offers no monthly breakdown without a currency', function (): void {
        expect(dashboard()->monthlyProfit)->toBe([]);
    });
});

describe('the screen', function (): void {
    it('redirects a guest to the login page', function (): void {
        $this->get('/dashboard')->assertRedirect('/login');
    });

    it('renders for a signed-in user', function (): void {
        $this->actingAs($this->operator)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('dashboard')->has('dashboard')->has('filters'));
    });

    it('says so plainly when nothing has been recorded', function (): void {
        $this->actingAs($this->operator)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->has('dashboard.currencies', 0));
    });

    it('carries the filters through the url', function (): void {
        movement(TransactionType::LoanGiven, party('Borrower'), '400000');

        $this->actingAs($this->operator)
            ->get('/dashboard?status=owes_us&currency=EGP')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', 'owes_us')
                ->where('filters.currency', 'EGP')
                ->has('dashboard.counterparties', 1)
            );
    });

    it('rejects a status that is not one of the four', function (): void {
        $this->actingAs($this->operator)
            ->get('/dashboard?status=nearly')
            ->assertSessionHasErrors('status');
    });

    it('rejects a period that ends before it begins', function (): void {
        $this->actingAs($this->operator)
            ->get('/dashboard?from=2026-06-30&to=2026-06-01')
            ->assertSessionHasErrors('to');
    });

    // Risk R1 on one more screen.
    it('sends every amount as a string', function (): void {
        movement(TransactionType::CreditDeposit, party('Holder'), '1000000');

        $props = $this->actingAs($this->operator)->get('/dashboard')->viewData('page')['props'];

        expect($props['dashboard']['cash_on_hand']['EGP']['amount'])->toBe('1000000.00')
            ->and($props['dashboard']['owed_to_them']['EGP']['amount'])->toBeString();
    });
});
