<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Money\CurrencyRegistry;
use App\Domain\Statement\StatementBuilder;
use App\Enums\BalanceBucket;
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
        type: TransactionType::CreditDeposit,
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
        type: TransactionType::CreditSettlement,
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
        creditIn('500000');

        $row = statementFor()->rows[0];

        expect($row->in?->toDisplayString())->toBe('500000.00')
            ->and($row->out)->toBeNull()
            ->and($row->bucket)->toBe(BalanceBucket::CreditTrust);
    });

    it('shows money going back out as out', function (): void {
        creditIn('500000');
        creditOut('200000');

        $row = statementFor()->rows[1];

        expect($row->out?->toDisplayString())->toBe('200000.00')
            ->and($row->in)->toBeNull();
    });

    it('runs the position down the page', function (): void {
        creditIn('3957540');
        creditOut('2574000');

        $rows = statementFor()->rows;

        expect($rows[0]->balanceAfter->toDisplayString())->toBe('3957540.00')
            ->and($rows[1]->balanceAfter->toDisplayString())->toBe('1383540.00');
    });

    it('totals what came in and what went out separately', function (): void {
        creditIn('3957540');
        creditOut('2574000');

        $statement = statementFor();

        expect($statement->totalIn['credit_trust']->toDisplayString())->toBe('3957540.00')
            ->and($statement->totalOut['credit_trust']->toDisplayString())->toBe('2574000.00')
            ->and($statement->closing['credit_trust']->toDisplayString())->toBe('1383540.00');
    });
});

/*
 * The reason the sheet's single signed column had to go. A party can be holding our
 * money while owing us money, and one number cannot say both.
 */
describe('positions are kept apart', function (): void {
    beforeEach(function (): void {
        creditIn('1000000');

        // They also took money against a receivable.
        $this->posting->post($this->rules->build(new TransactionInput(
            type: TransactionType::LoanGiven,
            currency: $this->egp,
            amount: $this->egp->money('400000'),
            occurredAt: new DateTimeImmutable('2026-06-05'),
            account: $this->safe,
            counterparty: $this->party,
        )));
    });

    it('reports each bucket on its own', function (): void {
        $statement = statementFor();

        expect($statement->closing['credit_trust']->toDisplayString())->toBe('1000000.00')
            ->and($statement->closing['receivable']->toDisplayString())->toBe('400000.00');
    });

    it('lists both buckets as in play', function (): void {
        expect(array_map(fn (BalanceBucket $b): string => $b->value, statementFor()->buckets))
            ->toBe(['receivable', 'credit_trust']);
    });

    // The two figures are 1,000,000 and 400,000. Anything that produced 600,000 would
    // have netted an obligation against a holding.
    it('offers no way to net them', function (): void {
        $statement = statementFor();

        expect(method_exists($statement, 'balance'))->toBeFalse()
            ->and(method_exists($statement, 'net'))->toBeFalse()
            ->and(method_exists($statement, 'total'))->toBeFalse();
    });

    // Money lent to them is value leaving us, even though it increases an asset.
    it('calls a loan out, not in', function (): void {
        $loan = collect(statementFor()->rows)->firstWhere('bucket', BalanceBucket::Receivable);

        expect($loan?->out?->toDisplayString())->toBe('400000.00')
            ->and($loan?->in)->toBeNull();
    });
});

describe('the period', function (): void {
    beforeEach(function (): void {
        creditIn('500000', '2026-05-10');
        creditIn('300000', '2026-06-10');
        creditOut('200000', '2026-06-20');
    });

    it('folds everything earlier into the opening position', function (): void {
        $statement = statementFor(StatementMode::Client, '2026-06-01');

        expect($statement->opening['credit_trust']->toDisplayString())->toBe('500000.00')
            ->and($statement->rows)->toHaveCount(2);
    });

    // The running balance continues from the opening rather than restarting at zero,
    // which is the whole point of an opening figure.
    it('carries the opening into the running position', function (): void {
        $rows = statementFor(StatementMode::Client, '2026-06-01')->rows;

        expect($rows[0]->balanceAfter->toDisplayString())->toBe('800000.00');
    });

    // The 20 June settlement is after the closing date, so neither the line nor its
    // effect on the position may appear.
    it('stops at the closing date', function (): void {
        $statement = statementFor(StatementMode::Client, null, '2026-06-15');

        expect($statement->rows)->toHaveCount(2)
            ->and($statement->closing['credit_trust']->toDisplayString())->toBe('800000.00')
            ->and($statement->totalOut['credit_trust']->isZero())->toBeTrue();
    });

    it('opens at zero when no start date is given', function (): void {
        expect(statementFor()->opening['credit_trust']->isZero())->toBeTrue();
    });
});

describe('me mode and client mode', function (): void {
    beforeEach(function (): void {
        $this->posting->post($this->rules->build(new TransactionInput(
            type: TransactionType::CreditDeposit,
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
            ->and($props['statement']['closing']['credit_trust']['amount'])->toBe('500000.00');
    });
});

describe('a declared opening that was never posted', function (): void {
    it('is reported rather than quietly merged in', function (): void {
        creditIn('500000');
        $this->party->setOpeningBalance(BalanceBucket::CreditTrust, $this->egp, $this->egp->money('123'));

        $statement = statementFor();

        expect($statement->declaredOpening['credit_trust']->toDisplayString())->toBe('123.00')
            // The ledger does not know about it, so the figures do not either.
            ->and($statement->closing['credit_trust']->toDisplayString())->toBe('500000.00');
    });
});
