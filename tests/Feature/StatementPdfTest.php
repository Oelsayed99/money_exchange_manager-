<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Money\CurrencyRegistry;
use App\Domain\Statement\StatementBuilder;
use App\Domain\Statement\StatementPdf;
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
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->safe = Account::factory()->create(['name' => 'Main safe']);
    $this->party = Counterparty::factory()->create(['name' => 'سالم التجريبي']);

    $this->operator = User::factory()->create();
    $this->operator->assignRole(Role::Operator->value);

    app(PostingService::class)->post(app(PostingRules::class)->build(new TransactionInput(
        type: TransactionType::CreditDeposit,
        currency: $this->egp,
        amount: $this->egp->money('3957540'),
        occurredAt: new DateTimeImmutable('2026-06-01'),
        account: $this->safe,
        counterparty: $this->party,
    )));

    // A margin on that line, so the two modes have something to differ about.
    Transaction::query()->update([
        'net_profit' => '13579.0000000000',
        'profit_currency_id' => $this->egp->id,
    ]);
});

function pdfFor(string $query = ''): TestResponse
{
    $test = test();

    return $test->actingAs($test->operator)
        ->get("/counterparties/{$test->party->id}/statement/pdf".($query === '' ? '' : "?{$query}"));
}

/**
 * What the document says, taken from the contents mPDF is given.
 *
 * Not from the PDF bytes. mPDF subsets the embedded fonts, so the drawn text becomes
 * glyph ids in a private encoding — grepping those bytes for "13579" finds nothing
 * whether or not the figure is on the page, and the negative assertions below would
 * pass while checking nothing at all.
 */
function statementHtml(StatementMode $mode): string
{
    $test = test();

    $statement = app(StatementBuilder::class)
        ->build($test->party, $test->egp, $mode);

    // Entities decoded, so assertions read as the words on the page rather than as
    // Blade's escaping of them.
    return html_entity_decode(
        app(StatementPdf::class)->html($statement),
        ENT_QUOTES,
        'UTF-8',
    );
}

describe('authorization', function (): void {
    it('requires authentication', function (): void {
        $this->get("/counterparties/{$this->party->id}/statement/pdf")->assertRedirect('/login');
    });

    it('refuses somebody who may not view the party', function (): void {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get("/counterparties/{$this->party->id}/statement/pdf")
            ->assertForbidden();
    });
});

describe('the document', function (): void {
    it('returns a pdf', function (): void {
        $response = pdfFor();

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');

        expect($response->content())->toStartWith('%PDF-')
            ->and(strlen($response->content()))->toBeGreaterThan(2000);
    });

    it('offers it as a download named after the party and currency', function (): void {
        pdfFor()->assertDownload();

        expect(pdfFor()->headers->get('Content-Disposition'))->toContain('-egp-')
            ->and(pdfFor()->headers->get('Content-Disposition'))->toEndWith('.pdf"');
    });

    it('has nothing to render for a party with no activity', function (): void {
        $fresh = Counterparty::factory()->create();

        $this->actingAs($this->operator)
            ->get("/counterparties/{$fresh->id}/statement/pdf")
            ->assertNotFound();
    });

    it('honours the same filters as the screen', function (): void {
        pdfFor('from=2026-07-01')->assertOk();
        pdfFor('to=2026-05-01')->assertOk();
    });

    it('rejects a period that ends before it begins', function (): void {
        pdfFor('from=2026-06-30&to=2026-06-01')->assertSessionHasErrors('to');
    });
});

/*
 * The whole point of the two modes. A client copy is a file that gets sent to somebody
 * outside the business, and a margin inside it cannot be taken back.
 */
describe('what reaches the client copy', function (): void {
    it('carries the figures the client is entitled to', function (): void {
        expect(statementHtml(StatementMode::Client))->toContain('3957540.00');
    });

    it('carries no margin at all', function (): void {
        expect(statementHtml(StatementMode::Client))->not->toContain('13579');
    });

    // The paired positive. Without it the assertion above could be passing because the
    // figure is nowhere in either document.
    it('carries the margin in my own copy', function (): void {
        expect(statementHtml(StatementMode::Internal))->toContain('13579');
    });

    it('leaves the profit column out of the client copy entirely', function (): void {
        expect(statementHtml(StatementMode::Client))->not->toContain(__('statements.columns.profit'))
            ->and(statementHtml(StatementMode::Internal))->toContain(__('statements.columns.profit'));
    });

    // A page that gets separated from the rest still has to say what it is.
    it('stamps which copy it is', function (): void {
        expect(statementHtml(StatementMode::Client))->toContain("Client's copy")
            ->and(statementHtml(StatementMode::Internal))->toContain('My copy');
    });

    // Client is the default, so a link built without a mode cannot leak by omission.
    it('defaults to the client copy', function (): void {
        $default = app(StatementBuilder::class)->build($this->party, $this->egp);

        expect($default->mode)->toBe(StatementMode::Client);
    });
});

describe('the position on the page', function (): void {
    it('states the position in words rather than a bare number', function (): void {
        expect(statementHtml(StatementMode::Client))->toContain('Client credit with us');
    });

    it('never prints a single combined figure', function (): void {
        expect(statementHtml(StatementMode::Client))->toContain('3957540.00');
    });
});

// mPDF was chosen over DomPDF for exactly this: an Arabic name has to survive being
// printed. DomPDF does no complex shaping and would mangle it.
describe('Arabic', function (): void {
    it('puts the Arabic name into the document', function (): void {
        expect(statementHtml(StatementMode::Client))->toContain('سالم التجريبي');
    });

    it('renders the whole document in Arabic without failing', function (): void {
        $this->operator->update(['locale' => 'ar']);

        $response = pdfFor();

        $response->assertOk();
        expect($response->content())->toStartWith('%PDF-')
            ->and(strlen($response->content()))->toBeGreaterThan(2000);
    });
});
