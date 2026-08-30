<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Money\CurrencyRegistry;
use App\Domain\Statement\StatementBuilder;
use App\Enums\Role;
use App\Enums\StatementMode;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->usd = Currency::query()->where('code', 'USD')->sole();

    $this->safe = Account::factory()->create(['name' => 'Main safe']);
    $this->party = Counterparty::factory()->create(['name' => 'سالم التجريبي']);

    $this->builder = app(StatementBuilder::class);
    $this->posting = app(PostingService::class);
    $this->rules = app(PostingRules::class);

    $this->operator = User::factory()->create();
    $this->operator->assignRole(Role::Operator->value);
});

/** Money arriving from the party and staying with us: their credit grows. */
function creditIn(string $amount, string $date = '2026-06-01'): void
{
    $test = test();

    $test->posting->post($test->rules->build(new TransactionInput(
        type: TransactionType::In,
        currency: $test->egp,
        amount: $test->egp->money($amount),
        occurredAt: new DateTimeImmutable($date),
        account: $test->safe,
        counterparty: $test->party,
    )));
}

/** Money going back out to them: their credit shrinks. */
function creditOut(string $amount, string $date = '2026-06-16'): void
{
    $test = test();

    $test->posting->post($test->rules->build(new TransactionInput(
        type: TransactionType::Out,
        currency: $test->egp,
        amount: $test->egp->money($amount),
        occurredAt: new DateTimeImmutable($date),
        account: $test->safe,
        counterparty: $test->party,
    )));
}

function statementFor(StatementMode $mode = StatementMode::Client, ?string $from = null, ?string $to = null)
{
    $test = test();

    return $test->builder->build(
        $test->party,
        $test->egp,
        $mode,
        $from === null ? null : Carbon::parse($from),
        $to === null ? null : Carbon::parse($to),
    );
}

// The owner's real page: nine deposits totalling 3,957,540, then a settlement of
// 2,574,000, leaving 1,383,540 of the client's money with us.
describe('the sheet it replaces', function (): void {
    it('shows money coming in as in, not out', function (): void {
        creditIn('581000');

        $statement = statementFor();
        $row = $statement->rows[0];

        expect($row->in?->toDisplayString())->toBe('581000.00')
            ->and($row->out)->toBeNull();
    });

    // The running column the spreadsheet had, with the sign meaning what the owner
    // says it means: negative is their money sitting with us.
    it('runs the balance down the page', function (): void {
        creditIn('581000', '2026-06-01');
        creditIn('436540', '2026-06-02');
        creditOut('200000', '2026-06-03');

        $statement = statementFor();

        expect(array_map(fn ($row) => $row->balanceAfter->toDisplayString(), $statement->rows))
            ->toBe(['-581000.00', '-1017540.00', '-817540.00'])
            ->and($statement->closing->toDisplayString())->toBe('-817540.00');
    });

    /*
     * The line the owner asked for: booked in pounds, arrived in dollars.
     *
     * The statement carries the client's figure in the column and what actually
     * changed hands beneath it — both amounts and the rate they were agreed at.
     */
    it('says what actually moved, and at what rate', function (): void {
        test()->posting->post(test()->rules->build(new TransactionInput(
            type: TransactionType::In,
            currency: test()->egp,
            amount: test()->egp->money('508500'),
            occurredAt: new DateTimeImmutable('2026-06-01'),
            account: test()->safe,
            counterparty: test()->party,
            cashCurrency: test()->usd,
            cashAmount: test()->usd->money('10000'),
            rate: '50.85',
        )));

        $row = statementFor()->rows[0];

        expect($row->movedAmount?->toDisplayString())->toBe('10000.00')
            ->and($row->movedAmount?->currency->code)->toBe('USD')
            // Not '50.850000000000'. The column pads; a statement should not.
            ->and($row->rate)->toBe('50.85');
    });

    it('leaves both off a line that moved in the currency it was booked in', function (): void {
        creditIn('899510');

        $row = statementFor()->rows[0];

        expect($row->movedAmount)->toBeNull()
            ->and($row->rate)->toBeNull();
    });

    it('totals what came in and what went out separately', function (): void {
        creditIn('581000');
        creditIn('436540');
        creditOut('200000');

        $statement = statementFor();

        expect($statement->totalIn->toDisplayString())->toBe('1017540.00')
            ->and($statement->totalOut->toDisplayString())->toBe('200000.00');
    });
});

/**
 * One balance, and which way it runs.
 *
 * There were four positions here and a column for each. The owner's objection was that
 * a party cannot both owe us and be owed by us — it is one thing and its difference.
 * See ADR 0032.
 */
describe('the running balance', function (): void {
    it('nets everything a party has done into one figure', function (): void {
        creditIn('899510');
        creditOut('14890');

        $statement = statementFor();

        expect($statement->closing->toDisplayString())->toBe('-884620.00')
            ->and($statement->theyOweUs())->toBeFalse();
    });

    it('reads positive when we have paid out more than we took in', function (): void {
        creditOut('50000');
        creditIn('20000');

        $statement = statementFor();

        expect($statement->closing->toDisplayString())->toBe('30000.00')
            ->and($statement->theyOweUs())->toBeTrue();
    });

    it('puts money going out in the out column', function (): void {
        creditOut('14890');

        $row = statementFor()->rows[0];

        expect($row->out?->toDisplayString())->toBe('14890.00')
            ->and($row->in)->toBeNull();
    });
});

describe('the period', function (): void {
    it('folds everything earlier into the opening balance', function (): void {
        creditIn('500000', '2026-05-01');
        creditIn('300000', '2026-06-15');

        $statement = statementFor(from: '2026-06-01');

        expect($statement->opening->toDisplayString())->toBe('-500000.00')
            ->and($statement->rows)->toHaveCount(1);
    });

    it('carries the opening into the running balance', function (): void {
        creditIn('500000', '2026-05-01');
        creditIn('300000', '2026-06-15');

        $statement = statementFor(from: '2026-06-01');

        expect($statement->rows[0]->balanceAfter->toDisplayString())->toBe('-800000.00');
    });

    it('stops at the closing date', function (): void {
        creditIn('500000', '2026-06-01');
        creditIn('300000', '2026-07-01');

        $statement = statementFor(to: '2026-06-30');

        expect($statement->rows)->toHaveCount(1)
            ->and($statement->closing->toDisplayString())->toBe('-500000.00');
    });

    it('opens at zero when no start date is given', function (): void {
        creditIn('500000', '2026-05-01');

        expect(statementFor()->opening->isZero())->toBeTrue();
    });
});

describe('me mode and client mode', function (): void {
    beforeEach(function (): void {
        $this->posting->post($this->rules->build(new TransactionInput(
            type: TransactionType::In,
            currency: $this->egp,
            amount: $this->egp->money('500000'),
            occurredAt: new DateTimeImmutable('2026-06-01'),
            account: $this->safe,
            counterparty: $this->party,
        )));
    });

    it('leaves profit out of the client copy entirely', function (): void {
        $statement = statementFor(StatementMode::Client);

        expect($statement->profit)->toBe([])
            ->and($statement->rows[0]->profit)->toBeNull();
    });

    // Section 9, enforced in the query rather than the template: in client mode the
    // profit columns are never selected, so there is nothing in the result set to leak
    // into a prop, a page source or a printed page.
    it('does not even load the profit columns for a client copy', function (): void {
        $statement = statementFor(StatementMode::Client);

        expect($statement->rows[0]->profit)->toBeNull();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        statementFor(StatementMode::Client);

        expect(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'net_profit')))
            ->toBeEmpty();
    });

    it('does load them for my own copy', function (): void {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        statementFor(StatementMode::Internal);

        expect(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'net_profit')))
            ->not->toBeEmpty();
    });
});

describe('the screen', function (): void {
    it('requires authentication', function (): void {
        $this->get("/counterparties/{$this->party->id}/statement")->assertRedirect('/login');
    });

    it('renders a statement', function (): void {
        creditIn('500000');

        $this->actingAs($this->operator)
            ->get("/counterparties/{$this->party->id}/statement")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('counterparties/statement')
                ->where('statement.currency', 'EGP')
                ->where('statement.shows_profit', false)
                ->has('statement.rows', 1)
            );
    });

    // The failure this guards against is the worst one available: a margin reaching a
    // page meant for the client. Inertia writes props into the document, so it is not
    // enough for React to skip rendering them — the figure has to be absent from the
    // page source. Checked against the figure itself, not a column name: "net_profit"
    // appears in the page legitimately, as a translated label.
    it('puts no profit figure in the page for a client copy', function (): void {
        creditIn('500000');

        Transaction::query()->update([
            'net_profit' => '13579.0000000000',
            'profit_currency_id' => $this->egp->id,
        ]);

        $client = $this->actingAs($this->operator)
            ->get("/counterparties/{$this->party->id}/statement?mode=client");

        $internal = $this->actingAs($this->operator)
            ->get("/counterparties/{$this->party->id}/statement?mode=internal");

        $client->assertOk()->assertInertia(fn ($page) => $page->where('statement.profit', null));

        expect($client->content())->not->toContain('13579')
            ->and($internal->content())->toContain('13579');
    });

    it('shows profit in my own copy', function (): void {
        creditIn('500000');

        $this->actingAs($this->operator)
            ->get("/counterparties/{$this->party->id}/statement?mode=internal")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('statement.shows_profit', true));
    });

    it('offers only the currencies this party has traded', function (): void {
        creditIn('500000');

        $this->actingAs($this->operator)
            ->get("/counterparties/{$this->party->id}/statement")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('currencies', 1)->where('currencies.0.code', 'EGP'));
    });

    // A page of zeros in a currency they have never touched invites the reader to
    // conclude something from it.
    it('refuses a currency the party has never dealt in', function (): void {
        creditIn('500000');

        $this->actingAs($this->operator)
            ->get("/counterparties/{$this->party->id}/statement?currency=USD")
            ->assertSessionHasErrors('currency');
    });

    it('says so plainly when there is nothing to state', function (): void {
        $fresh = Counterparty::factory()->create();

        $this->actingAs($this->operator)
            ->get("/counterparties/{$fresh->id}/statement")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('statement', null)->has('currencies', 0));
    });

    it('rejects a period that ends before it begins', function (): void {
        creditIn('500000');

        $this->actingAs($this->operator)
            ->get("/counterparties/{$this->party->id}/statement?from=2026-06-30&to=2026-06-01")
            ->assertSessionHasErrors('to');
    });

    // Risk R1 once more, on the document a client actually receives.
    it('sends every amount as a string', function (): void {
        creditIn('500000');

        $props = $this->actingAs($this->operator)
            ->get("/counterparties/{$this->party->id}/statement")
            ->viewData('page')['props'];

        expect($props['statement']['rows'][0]['in']['amount'])->toBeString()
            ->and($props['statement']['closing']['amount'])->toBe('-500000.00')
            ->and($props['statement']['they_owe_us'])->toBeFalse();
    });
});

describe('a declared opening that was never posted', function (): void {
    it('is reported rather than quietly merged in', function (): void {
        creditIn('500000');
        $this->party->setOpeningBalance($this->egp, $this->egp->money('123'));

        $statement = statementFor();

        expect($statement->declaredOpening?->toDisplayString())->toBe('123.00')
            // The ledger does not know about it, so the figures do not either.
            ->and($statement->closing->toDisplayString())->toBe('-500000.00');
    });
});
