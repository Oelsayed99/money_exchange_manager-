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

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->safe = Account::factory()->create(['name' => 'Main safe']);
    $this->client = Counterparty::factory()->create(['name' => 'سالم التجريبي']);

    $this->operator = User::factory()->create();
    $this->operator->assignRole(Role::Operator->value);

    app(PostingService::class)->post(app(PostingRules::class)->build(new TransactionInput(
        type: TransactionType::CreditDeposit,
        currency: $this->egp,
        amount: $this->egp->money('3957540'),
        occurredAt: new DateTimeImmutable('2026-06-01'),
        account: $this->safe,
        counterparty: $this->client,
        reference: 'REF-1',
    )));

    Transaction::query()->update([
        'net_profit' => '13579.0000000000',
        'profit_currency_id' => $this->egp->id,
    ]);
});

/** The body of a streamed download, as bytes. */
function bytes(TestResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

function statementCsv(string $query = ''): TestResponse
{
    $test = test();

    return $test->actingAs($test->operator)
        ->get("/counterparties/{$test->client->id}/statement/csv".($query === '' ? '' : "?{$query}"));
}

function transactionsCsv(string $query = ''): TestResponse
{
    return test()->actingAs(test()->operator)
        ->get('/transactions/csv'.($query === '' ? '' : "?{$query}"));
}

describe('authorization', function (): void {
    it('requires authentication', function (): void {
        $this->get("/counterparties/{$this->client->id}/statement/csv")->assertRedirect('/login');
        $this->get('/transactions/csv')->assertRedirect('/login');
    });

    it('refuses somebody who may not view the party', function (): void {
        $this->actingAs(User::factory()->create())
            ->get("/counterparties/{$this->client->id}/statement/csv")
            ->assertForbidden();
    });
});

describe('the file', function (): void {
    it('is offered as a csv download', function (): void {
        $response = statementCsv();

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload();

        expect($response->headers->get('Content-Disposition'))->toEndWith('.csv"');
    });

    // Without a byte-order mark Excel on Windows assumes the system codepage and
    // renders an Arabic name as rubbish.
    it('opens with a UTF-8 byte-order mark', function (): void {
        expect(bytes(statementCsv()))->toStartWith("\xEF\xBB\xBF");
    });

    it('carries Arabic through intact', function (): void {
        expect(bytes(transactionsCsv()))->toContain('سالم التجريبي');
    });

    it('carries the figures', function (): void {
        expect(bytes(statementCsv()))->toContain('3957540.00');
    });

    // Grouped digits belong on a page. In a column something is going to add up, a
    // thousands separator either splits the cell or turns the number into text.
    it('writes plain decimals, not the grouped form', function (): void {
        expect(bytes(statementCsv()))->not->toContain('3,957,540.00');
    });

    it('has nothing to export for a party with no activity', function (): void {
        $fresh = Counterparty::factory()->create();

        $this->actingAs($this->operator)
            ->get("/counterparties/{$fresh->id}/statement/csv")
            ->assertNotFound();
    });
});

/*
 * The same property the PDF is held to, on a file that is even easier to forward by
 * accident than a PDF is.
 */
describe('what reaches the client copy', function (): void {
    it('carries no margin', function (): void {
        expect(bytes(statementCsv('mode=client')))->not->toContain('13579');
    });

    // The paired positive, so the assertion above cannot pass by the figure being
    // absent from both.
    it('carries the margin in my own copy', function (): void {
        expect(bytes(statementCsv('mode=internal')))->toContain('13579');
    });

    it('leaves the profit column out of the client copy entirely', function (): void {
        expect(bytes(statementCsv('mode=client')))->not->toContain(__('statements.columns.profit'))
            ->and(bytes(statementCsv('mode=internal')))->toContain(__('statements.columns.profit'));
    });

    it('defaults to the client copy', function (): void {
        expect(bytes(statementCsv()))->not->toContain('13579');
    });
});

/*
 * A spreadsheet reads a cell beginning = + - @ as a formula, so a name is executable
 * content in whatever opens the file.
 */
describe('formula injection', function (): void {
    beforeEach(function (): void {
        $this->attacker = Counterparty::factory()->create(['name' => '=HYPERLINK("http://evil","click")']);

        app(PostingService::class)->post(app(PostingRules::class)->build(new TransactionInput(
            type: TransactionType::CreditDeposit,
            currency: $this->egp,
            amount: $this->egp->money('100'),
            occurredAt: new DateTimeImmutable('2026-06-02'),
            account: $this->safe,
            counterparty: $this->attacker,
            reference: '@SUM(1+1)',
        )));
    });

    it('neutralises a cell that would otherwise be a formula', function (): void {
        $csv = bytes(transactionsCsv());

        expect($csv)->toContain("'=HYPERLINK")
            ->and($csv)->toContain("'@SUM(1+1)");
    });

    // A negative amount legitimately begins with a minus. Quoting it would turn the
    // whole column into text and break every sum built on it.
    it('leaves a negative number alone', function (): void {
        Transaction::query()->update(['net_profit' => '-500.0000000000', 'profit_currency_id' => $this->egp->id]);

        $csv = bytes(statementCsv('mode=internal'));

        expect($csv)->toContain('-500.00')
            ->and($csv)->not->toContain("'-500.00");
    });
});

describe('the transactions export', function (): void {
    // On screen the legs stack in one cell, which a spreadsheet cannot sum. One row
    // per leg is the shape a pivot table can work on.
    it('writes one row per leg', function (): void {
        $lines = array_values(array_filter(explode("\n", bytes(transactionsCsv()))));

        // A heading row plus one row for the single-leg credit deposit.
        expect(count($lines))->toBe(2);
    });

    it('honours the same filters as the list', function (): void {
        expect(bytes(transactionsCsv('type=credit_deposit')))->toContain('REF-1')
            ->and(bytes(transactionsCsv('type=expense')))->not->toContain('REF-1');
    });

    it('rejects a period that ends before it begins', function (): void {
        transactionsCsv('from=2026-07-01&to=2026-06-01')->assertSessionHasErrors('to');
    });
});
