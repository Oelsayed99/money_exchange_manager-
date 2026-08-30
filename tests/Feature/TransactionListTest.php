<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Money\CurrencyRegistry;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->usd = Currency::query()->where('code', 'USD')->sole();

    $this->safe = Account::factory()->create(['name' => 'Main safe']);
    $this->bank = Account::factory()->create(['name' => 'Bank']);
    $this->client = Counterparty::factory()->create(['name' => 'سالم التجريبي']);

    $this->rules = app(PostingRules::class);
    $this->posting = app(PostingService::class);

    $this->operator = User::factory()->create();
    $this->operator->assignRole(Role::Operator->value);

    $this->viewer = User::factory()->create();
    $this->viewer->assignRole(Role::Viewer->value);
});

function record(TransactionType $type, array $overrides = []): Transaction
{
    $test = test();
    $currency = $overrides['currency'] ?? $test->egp;

    return $test->posting->post($test->rules->build(new TransactionInput(
        type: $type,
        currency: $currency,
        amount: $currency->money($overrides['amount'] ?? '1000'),
        occurredAt: new DateTimeImmutable($overrides['date'] ?? '2026-06-10'),
        account: $overrides['account'] ?? $test->safe,
        destinationAccount: $overrides['destination'] ?? null,
        counterparty: $overrides['counterparty'] ?? null,
        reference: $overrides['reference'] ?? null,
        description: $overrides['description'] ?? null,
    )));
}

function listing(string $query = ''): TestResponse
{
    return test()->actingAs(test()->operator)->get('/transactions'.($query === '' ? '' : "?{$query}"));
}

describe('authorization', function (): void {
    it('requires authentication', function (): void {
        $this->get('/transactions')->assertRedirect('/login');
    });

    // Reading the ledger is a lesser thing than writing to it, so a viewer may.
    it('lets a viewer read it', function (): void {
        $this->actingAs($this->viewer)->get('/transactions')->assertOk();
    });

    it('refuses somebody with no permissions at all', function (): void {
        $this->actingAs(User::factory()->create())->get('/transactions')->assertForbidden();
    });
});

describe('what it shows', function (): void {
    it('lists a transaction with its legs', function (): void {
        record(TransactionType::Deposit, ['amount' => '5000', 'reference' => 'REF-1']);

        listing()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('transactions/index')
                ->has('transactions.data', 1)
                ->where('transactions.data.0.type', 'deposit')
                ->where('transactions.data.0.status', 'posted')
                ->where('transactions.data.0.reference', 'REF-1')
                ->has('transactions.data.0.legs', 1)
            );
    });

    // The reason there is no single amount column: an exchange moves two currencies
    // and neither is "the" amount.
    it('shows both sides of a movement that has two', function (): void {
        record(TransactionType::Transfer, ['amount' => '2000', 'destination' => $this->bank]);

        $legs = listing()->viewData('page')['props']['transactions']['data'][0]['legs'];

        expect(count($legs))->toBeGreaterThan(1);
    });

    it('shows a transaction with no counterparty at all', function (): void {
        record(TransactionType::Expense, ['amount' => '250']);

        listing()->assertInertia(fn (Assert $page) => $page->where('transactions.data.0.counterparty', null));
    });

    it('names the counterparty when there is one', function (): void {
        record(TransactionType::In, ['counterparty' => $this->client]);

        listing()->assertInertia(fn (Assert $page) => $page
            ->where('transactions.data.0.counterparty.name', 'سالم التجريبي'));
    });

    it('puts the newest first', function (): void {
        record(TransactionType::Deposit, ['date' => '2026-05-01', 'reference' => 'older']);
        record(TransactionType::Deposit, ['date' => '2026-07-01', 'reference' => 'newer']);

        listing()->assertInertia(fn (Assert $page) => $page->where('transactions.data.0.reference', 'newer'));
    });

    it('marks a reversal and says what it reverses', function (): void {
        $original = record(TransactionType::Deposit, ['amount' => '5000']);
        app(PostingService::class)->reverse($original);

        listing('status=posted')->assertOk();

        $reversal = collect(listing()->viewData('page')['props']['transactions']['data'])
            ->firstWhere('is_reversal', true);

        expect($reversal['reverses_id'])->toBe($original->id);
    });

    // Risk R1 on one more screen.
    it('sends every amount as a string', function (): void {
        record(TransactionType::Deposit, ['amount' => '5000']);

        $legs = listing()->viewData('page')['props']['transactions']['data'][0]['legs'];

        expect($legs[0]['amount']['amount'])->toBe('5000.00');
    });
});

describe('filtering', function (): void {
    beforeEach(function (): void {
        record(TransactionType::Deposit, ['amount' => '5000', 'date' => '2026-05-01', 'reference' => 'MAY']);
        record(TransactionType::In, ['amount' => '9000', 'date' => '2026-06-10', 'counterparty' => $this->client, 'description' => 'trust money']);
        record(TransactionType::Deposit, ['amount' => '700', 'date' => '2026-07-01', 'currency' => $this->usd]);
    });

    it('narrows by type', function (): void {
        listing('type=in')->assertInertia(fn (Assert $page) => $page->has('transactions.data', 1));
    });

    it('narrows by counterparty', function (): void {
        listing("counterparty={$this->client->id}")->assertInertia(fn (Assert $page) => $page->has('transactions.data', 1));
    });

    // Through the legs, because an exchange has no single currency column to match on.
    it('narrows by currency through the legs', function (): void {
        listing('currency=USD')->assertInertia(fn (Assert $page) => $page->has('transactions.data', 1));
        listing('currency=EGP')->assertInertia(fn (Assert $page) => $page->has('transactions.data', 2));
    });

    it('narrows by date, inclusive at both ends', function (): void {
        listing('from=2026-06-10&to=2026-06-10')->assertInertia(fn (Assert $page) => $page->has('transactions.data', 1));
    });

    it('searches the reference and the notes', function (): void {
        listing('search=MAY')->assertInertia(fn (Assert $page) => $page->has('transactions.data', 1));
        listing('search=trust')->assertInertia(fn (Assert $page) => $page->has('transactions.data', 1));
    });

    it('combines filters rather than replacing them', function (): void {
        listing('type=deposit&currency=EGP')->assertInertia(fn (Assert $page) => $page->has('transactions.data', 1));
    });

    it('keeps the filters in the props so the form stays filled in', function (): void {
        listing('type=deposit&search=MAY')->assertInertia(fn (Assert $page) => $page
            ->where('filters.type', 'deposit')
            ->where('filters.search', 'MAY'));
    });

    it('rejects a type that does not exist', function (): void {
        listing('type=nonsense')->assertSessionHasErrors('type');
    });

    it('rejects a period that ends before it begins', function (): void {
        listing('from=2026-07-01&to=2026-06-01')->assertSessionHasErrors('to');
    });
});

describe('paging', function (): void {
    it('pages long lists and carries the filters across pages', function (): void {
        foreach (range(1, 55) as $n) {
            record(TransactionType::Deposit, ['amount' => (string) (100 + $n)]);
        }

        listing()->assertInertia(fn (Assert $page) => $page
            ->has('transactions.data', 50)
            ->where('transactions.meta.total', 55)
            ->where('transactions.meta.last_page', 2));

        $next = listing('type=deposit')->viewData('page')['props']['transactions']['links']['next'];

        expect($next)->toContain('type=deposit')->and($next)->toContain('page=2');
    });
});
