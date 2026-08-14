<?php

declare(strict_types=1);

use App\Domain\Exchange\ExchangeInput;
use App\Domain\Exchange\ExchangeService;
use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingService;
use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\Money;
use App\Enums\LedgerAccountSubkind;
use App\Enums\LegRole;
use App\Enums\ProfitMethod;
use App\Enums\ProfitStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Currency;
use App\Models\LedgerAccount;
use App\Models\LedgerBalance;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use Database\Seeders\CurrencySeeder;

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->usd = Currency::query()->where('code', 'USD')->sole();

    $this->resolver = app(LedgerAccountResolver::class);
    $this->exchange = app(ExchangeService::class);

    $this->egpSafe = Account::factory()->create(['name' => 'EGP safe']);
    $this->usdSafe = Account::factory()->create(['name' => 'USD safe']);
});

/** The real deal: 2,574,000 EGP in for 50,000 USD out, cost 51.20. */
function realDeal(array $overrides = []): ExchangeInput
{
    $test = test();

    return new ExchangeInput(
        receivedCurrency: $test->egp,
        receivedAmount: $test->egp->money($overrides['received'] ?? '2574000'),
        receivedInto: $test->egpSafe,
        deliveredCurrency: $test->usd,
        deliveredAmount: $test->usd->money($overrides['delivered'] ?? '50000'),
        deliveredFrom: $test->usdSafe,
        occurredAt: now(),
        profitMethod: $overrides['profitMethod'] ?? ProfitMethod::RateDifference,
        costRate: array_key_exists('costRate', $overrides) ? $overrides['costRate'] : '51.20',
        feesCharged: $overrides['fees'] ?? null,
        expenses: $overrides['expenses'] ?? null,
        commissions: $overrides['commissions'] ?? null,
    );
}

function balance(LedgerAccount $account): string
{
    $row = LedgerBalance::query()->where('ledger_account_id', $account->id)->first();

    return $row === null ? '0' : $row->confirmed()->toDisplayString();
}

describe('recording an exchange', function (): void {
    it('moves both currencies the right way', function (): void {
        $this->exchange->record(realDeal());

        expect(balance($this->resolver->forAccount($this->egpSafe, $this->egp)))->toBe('2574000.00')
            ->and(balance($this->resolver->forAccount($this->usdSafe, $this->usd)))->toBe('-50000.00');
    });

    it('records both legs as they happened', function (): void {
        $transaction = $this->exchange->record(realDeal());

        $legs = $transaction->legs->keyBy(fn ($leg) => $leg->role->value);

        expect($transaction->type)->toBe(TransactionType::CurrencyExchange)
            ->and($legs[LegRole::Received->value]->amount->toDisplayString())->toBe('2574000.00')
            ->and($legs[LegRole::Delivered->value]->amount->toDisplayString())->toBe('50000.00');
    });

    it('recognises the margin as trading profit', function (): void {
        $this->exchange->record(realDeal());

        expect(balance($this->resolver->system(LedgerAccountSubkind::TradingProfit, $this->egp)))
            ->toBe('14000.00');
    });
});

// The property the clearing accounts exist for. Once profit is recognised, fx_position
// in the received currency holds exactly the cost value, and at the cost rate that is
// the same money as the delivered leg. A residual means an unrecognised spread.
describe('the clearing accounts self-check', function (): void {
    it('leaves the received side holding exactly the cost value', function (): void {
        $this->exchange->record(realDeal());

        // 2,574,000 credited, 14,000 debited back = 2,560,000 = 50,000 × 51.20.
        expect(balance($this->resolver->system(LedgerAccountSubkind::FxPosition, $this->egp)))
            ->toBe('-2560000.00');
    });

    it('leaves the delivered side holding the delivered amount', function (): void {
        $this->exchange->record(realDeal());

        expect(balance($this->resolver->system(LedgerAccountSubkind::FxPosition, $this->usd)))
            ->toBe('50000.00');
    });

    // Valued at the cost rate the two sides are the same money, so the position is flat.
    it('nets to zero when the two sides are valued at cost', function (): void {
        $this->exchange->record(realDeal());

        $egpSide = LedgerBalance::query()
            ->where('ledger_account_id', $this->resolver->system(LedgerAccountSubkind::FxPosition, $this->egp)->id)
            ->sole()->confirmed();

        $usdSide = LedgerBalance::query()
            ->where('ledger_account_id', $this->resolver->system(LedgerAccountSubkind::FxPosition, $this->usd)->id)
            ->sole()->confirmed();

        // The USD side, valued at 51.20, against the EGP side.
        $valued = $usdSide->multipliedBy('51.20');

        expect($valued->plus(Money::of($egpSide->toStorageString(), $valued->currency))->isZero())->toBeTrue();
    });

    it('stays flat across several deals at different rates', function (): void {
        $this->exchange->record(realDeal(['received' => '2574000', 'delivered' => '50000', 'costRate' => '51.20']));
        $this->exchange->record(realDeal(['received' => '1544400', 'delivered' => '30000', 'costRate' => '51.20']));
        $this->exchange->record(realDeal(['received' => '914400', 'delivered' => '18000', 'costRate' => '50.50']));

        $egpSide = LedgerBalance::query()
            ->where('ledger_account_id', $this->resolver->system(LedgerAccountSubkind::FxPosition, $this->egp)->id)
            ->sole()->confirmed();

        // 50,000×51.20 + 30,000×51.20 + 18,000×50.50 = 4,096,000 + 909,000 = 5,005,000.
        expect($egpSide->toDisplayString())->toBe('-5005000.00');
    });
});

describe('stored figures', function (): void {
    // Section 3: store the inputs as well as the outputs, and never recompute.
    it('keeps every input and every output', function (): void {
        $transaction = $this->exchange->record(realDeal());

        expect($transaction->customer_rate)->toBe('51.480000000000')
            ->and($transaction->cost_rate)->toBe('51.200000000000')
            ->and($transaction->customer_value)->toBe('2574000.0000000000')
            ->and($transaction->cost_value)->toBe('2560000.0000000000')
            ->and($transaction->gross_profit)->toBe('14000.0000000000')
            ->and($transaction->net_profit)->toBe('14000.0000000000')
            ->and($transaction->profit_method)->toBe(ProfitMethod::RateDifference)
            ->and($transaction->profit_currency_id)->toBe($this->egp->id);
    });

    it('finalises the profit when the deal is posted', function (): void {
        expect($this->exchange->record(realDeal())->profit_status)->toBe(ProfitStatus::Finalised);
    });

    // A deal still in flight has only an estimate.
    it('leaves the profit an estimate while the deal is pending', function (): void {
        $transaction = $this->exchange->record(realDeal(), TransactionStatus::Pending);

        expect($transaction->profit_status)->toBe(ProfitStatus::Estimated);
    });

    // One create, not a create followed by an edit.
    it('writes the figures with the transaction rather than editing it after', function (): void {
        $transaction = $this->exchange->record(realDeal());

        expect($transaction->auditLogs()->where('event', 'updated')->count())->toBe(0)
            ->and($transaction->auditLogs()->where('event', 'created')->count())->toBe(1);
    });
});

describe('fees, expenses and commissions', function (): void {
    it('posts a fee as income and adds it to the cash received', function (): void {
        $this->exchange->record(realDeal(['fees' => $this->egp->money('500')]));

        expect(balance($this->resolver->system(LedgerAccountSubkind::FeesIncome, $this->egp)))->toBe('500.00')
            ->and(balance($this->resolver->forAccount($this->egpSafe, $this->egp)))->toBe('2574500.00');
    });

    it('posts an expense against the cash received', function (): void {
        $this->exchange->record(realDeal(['expenses' => $this->egp->money('300')]));

        expect(balance($this->resolver->system(LedgerAccountSubkind::Expense, $this->egp)))->toBe('300.00')
            ->and(balance($this->resolver->forAccount($this->egpSafe, $this->egp)))->toBe('2573700.00');
    });

    it('posts a commission as its own expense', function (): void {
        $this->exchange->record(realDeal(['commissions' => $this->egp->money('200')]));

        expect(balance($this->resolver->system(LedgerAccountSubkind::CommissionExpense, $this->egp)))->toBe('200.00');
    });

    it('carries them through to net profit', function (): void {
        $transaction = $this->exchange->record(realDeal([
            'fees' => $this->egp->money('500'),
            'expenses' => $this->egp->money('300'),
            'commissions' => $this->egp->money('200'),
        ]));

        // 14,000 + 500 − 300 − 200 = 14,000.
        expect($transaction->net_profit)->toBe('14000.0000000000')
            ->and($transaction->gross_profit)->toBe('14000.0000000000');
    });
});

describe('losses', function (): void {
    // Entries are always positive; a loss is the same entry with the sides exchanged.
    it('posts a loss against trading profit', function (): void {
        $this->exchange->record(realDeal(['costRate' => '52.00']));

        // 50,000 × 52.00 = 2,600,000 cost against 2,574,000 received: a 26,000 loss.
        expect(balance($this->resolver->system(LedgerAccountSubkind::TradingProfit, $this->egp)))
            ->toBe('-26000.00');
    });

    it('still balances every currency', function (): void {
        $this->exchange->record(realDeal(['costRate' => '52.00']));

        $this->artisan('ledger:verify --transactions')->assertExitCode(0);
    });
});

describe('no-profit exchanges', function (): void {
    // Moving our own money between currencies earns nothing, and writes no profit entry.
    it('writes no profit entry at all', function (): void {
        $transaction = $this->exchange->record(realDeal(['profitMethod' => ProfitMethod::None, 'costRate' => null]));

        expect($transaction->entries)->toHaveCount(4)
            ->and($transaction->gross_profit)->toBe('0.0000000000');
    });
});

describe('integrity', function (): void {
    it('balances every currency it touches', function (): void {
        $this->exchange->record(realDeal([
            'fees' => $this->egp->money('500'),
            'expenses' => $this->egp->money('300'),
        ]));

        $this->artisan('ledger:verify --transactions')->assertExitCode(0);
    });

    it('survives a rebuild unchanged', function (): void {
        $this->exchange->record(realDeal());

        $before = balance($this->resolver->system(LedgerAccountSubkind::TradingProfit, $this->egp));

        $this->artisan('ledger:rebuild')->assertExitCode(0);

        expect(balance($this->resolver->system(LedgerAccountSubkind::TradingProfit, $this->egp)))->toBe($before);
    });

    it('can be reversed, taking the profit back with it', function (): void {
        $transaction = $this->exchange->record(realDeal());

        app(PostingService::class)->reverse($transaction);

        expect(balance($this->resolver->system(LedgerAccountSubkind::TradingProfit, $this->egp)))->toBe('0.00')
            ->and(balance($this->resolver->forAccount($this->usdSafe, $this->usd)))->toBe('0.00');

        $this->artisan('ledger:verify')->assertExitCode(0);
    });
});

describe('preview', function (): void {
    // The preview and the stored figures come from one implementation, so they cannot
    // disagree — which is the point of computing the preview on the server.
    it('matches what recording the same deal stores', function (): void {
        $input = realDeal();

        $preview = $this->exchange->preview($input);
        $transaction = $this->exchange->record($input);

        expect($preview->grossProfit->toStorageString())->toBe($transaction->gross_profit)
            ->and($preview->netProfit->toStorageString())->toBe($transaction->net_profit)
            ->and($preview->customerRate)->toBe($transaction->customer_rate);
    });

    it('records nothing', function (): void {
        $this->exchange->preview(realDeal());

        expect(Transaction::query()->count())->toBe(0)
            ->and(LedgerEntry::query()->count())->toBe(0);
    });
});
