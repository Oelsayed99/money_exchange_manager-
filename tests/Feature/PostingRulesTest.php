<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Enums\BalanceBucket;
use App\Enums\EntryDirection;
use App\Enums\LedgerAccountSubkind;
use App\Enums\LegRole;
use App\Enums\MovementMethod;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\LedgerAccount;
use App\Models\LedgerBalance;
use App\Models\LedgerEntry;
use Database\Seeders\CurrencySeeder;

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->usd = Currency::query()->where('code', 'USD')->sole();

    $this->resolver = app(LedgerAccountResolver::class);
    $this->rules = app(PostingRules::class);
    $this->posting = app(PostingService::class);

    $this->safe = Account::factory()->create(['name' => 'Office safe']);
    $this->bank = Account::factory()->create(['name' => 'Bank']);
    $this->party = Counterparty::factory()->create(['name' => 'سالم التجريبي']);
});

function input(TransactionType $type, string $amount, array $extra = []): TransactionInput
{
    $test = test();

    return new TransactionInput(
        type: $type,
        currency: $test->egp,
        amount: $test->egp->money($amount),
        occurredAt: now(),
        account: $extra['account'] ?? $test->safe,
        destinationAccount: $extra['destinationAccount'] ?? null,
        counterparty: $extra['counterparty'] ?? null,
        bucket: $extra['bucket'] ?? null,
        method: $extra['method'] ?? null,
    );
}

/** The signed balance of one ledger account, straight from the cache. */
function balanceOf(LedgerAccount $account): string
{
    return LedgerBalance::query()->where('ledger_account_id', $account->id)->sole()->confirmed()->toDisplayString();
}

describe('input validation', function (): void {
    it('refuses a negative amount', function (): void {
        expect(fn () => input(TransactionType::Deposit, '-1'))
            ->toThrow(InvalidArgumentException::class, 'would say it twice');
    });

    it('refuses an amount in a currency other than the one named', function (): void {
        expect(fn () => new TransactionInput(
            type: TransactionType::Deposit,
            currency: $this->egp,
            amount: $this->usd->money('1'),
            occurredAt: now(),
        ))->toThrow(CurrencyMismatch::class);
    });

    it('names what is missing rather than posting the wrong thing', function (): void {
        expect(fn () => $this->rules->build(input(TransactionType::CreditDeposit, '100')))
            ->toThrow(InvalidArgumentException::class, 'needs a counterparty');

        expect(fn () => $this->rules->build(input(TransactionType::Transfer, '100')))
            ->toThrow(InvalidArgumentException::class, 'needs a destination account');
    });
});

describe('capital in and out', function (): void {
    it('records a deposit as capital going in', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::Deposit, '1000')));

        expect(balanceOf($this->resolver->forAccount($this->safe, $this->egp)))->toBe('1000.00')
            ->and(balanceOf($this->resolver->system(LedgerAccountSubkind::Capital, $this->egp)))->toBe('1000.00');
    });

    it('records a withdrawal as capital coming out', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::Deposit, '1000')));
        $this->posting->post($this->rules->build(input(TransactionType::Withdrawal, '400')));

        expect(balanceOf($this->resolver->forAccount($this->safe, $this->egp)))->toBe('600.00')
            ->and(balanceOf($this->resolver->system(LedgerAccountSubkind::Capital, $this->egp)))->toBe('600.00');
    });
});

describe('transfer', function (): void {
    it('moves money between two custody locations', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::Deposit, '1000')));
        $this->posting->post($this->rules->build(input(TransactionType::Transfer, '250', [
            'destinationAccount' => $this->bank,
        ])));

        expect(balanceOf($this->resolver->forAccount($this->safe, $this->egp)))->toBe('750.00')
            ->and(balanceOf($this->resolver->forAccount($this->bank, $this->egp)))->toBe('250.00');
    });

    it('records both sides as legs', function (): void {
        $transaction = $this->posting->post($this->rules->build(input(TransactionType::Transfer, '250', [
            'destinationAccount' => $this->bank,
        ])));

        expect($transaction->legs->pluck('role')->all())->toBe([LegRole::Delivered, LegRole::Received]);
    });

    it('refuses to transfer an account to itself', function (): void {
        expect(fn () => $this->rules->build(input(TransactionType::Transfer, '100', [
            'destinationAccount' => $this->safe,
        ])))->toThrow(DomainException::class, 'two different accounts');
    });
});

describe('counterparty movements', function (): void {
    it('increases a receivable when money is lent', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::LoanGiven, '500', [
            'counterparty' => $this->party,
        ])));

        expect(balanceOf($this->resolver->forBucket(BalanceBucket::Receivable, $this->party, $this->egp)))->toBe('500.00')
            ->and(balanceOf($this->resolver->forAccount($this->safe, $this->egp)))->toBe('-500.00');
    });

    it('reduces the receivable when it is settled', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::LoanGiven, '500', ['counterparty' => $this->party])));
        $this->posting->post($this->rules->build(input(TransactionType::ReceivableSettlement, '200', ['counterparty' => $this->party])));

        expect(balanceOf($this->resolver->forBucket(BalanceBucket::Receivable, $this->party, $this->egp)))->toBe('300.00');
    });

    it('increases a payable when money is borrowed', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::LoanReceived, '800', ['counterparty' => $this->party])));

        expect(balanceOf($this->resolver->forBucket(BalanceBucket::Payable, $this->party, $this->egp)))->toBe('800.00');
    });

    // Same entries, different intent. Reporting tells them apart by the type.
    it('posts money received and a receivable settlement identically', function (): void {
        $a = $this->posting->post($this->rules->build(input(TransactionType::MoneyReceived, '100', ['counterparty' => $this->party])));
        $b = $this->posting->post($this->rules->build(input(TransactionType::ReceivableSettlement, '100', ['counterparty' => $this->party])));

        expect($a->entries->pluck('direction')->all())->toBe($b->entries->pluck('direction')->all())
            ->and($a->entries->pluck('ledger_account_id')->all())->toBe($b->entries->pluck('ledger_account_id')->all())
            ->and($a->type)->not->toBe($b->type);
    });

    // Section 5: a party can owe money and hold money at once, and the two never meet.
    it('keeps a receivable and a credit balance against the same party apart', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::CreditDeposit, '899510', ['counterparty' => $this->party])));
        $this->posting->post($this->rules->build(input(TransactionType::LoanGiven, '14890', ['counterparty' => $this->party])));

        expect(balanceOf($this->resolver->forBucket(BalanceBucket::CreditTrust, $this->party, $this->egp)))->toBe('899510.00')
            ->and(balanceOf($this->resolver->forBucket(BalanceBucket::Receivable, $this->party, $this->egp)))->toBe('14890.00');
    });
});

describe('the real statement', function (): void {
    it('reproduces the credit balance through nine deposits and one settlement', function (): void {
        foreach (['581000', '436540', '500000', '560000', '450000', '275000', '463330', '341670', '350000'] as $amount) {
            $this->posting->post($this->rules->build(input(TransactionType::CreditDeposit, $amount, [
                'counterparty' => $this->party,
                'method' => MovementMethod::Transfer,
            ])));
        }

        expect(balanceOf($this->resolver->forBucket(BalanceBucket::CreditTrust, $this->party, $this->egp)))->toBe('3957540.00');

        // 50,000 USD at 51.48 — the EGP value leaving the liability.
        $this->posting->post($this->rules->build(input(TransactionType::CreditSettlement, '2574000', [
            'counterparty' => $this->party,
        ])));

        expect(balanceOf($this->resolver->forBucket(BalanceBucket::CreditTrust, $this->party, $this->egp)))->toBe('1383540.00');
    });

    it('records how the money arrived', function (): void {
        $transaction = $this->posting->post($this->rules->build(input(TransactionType::CreditDeposit, '950000', [
            'counterparty' => $this->party,
            'method' => MovementMethod::Cash,
        ])));

        expect($transaction->method)->toBe(MovementMethod::Cash);
    });
});

describe('opening balances', function (): void {
    it('posts a custody location opening balance against equity', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::OpeningBalance, '25000')));

        expect(balanceOf($this->resolver->forAccount($this->safe, $this->egp)))->toBe('25000.00')
            ->and(balanceOf($this->resolver->system(LedgerAccountSubkind::OpeningEquity, $this->egp)))->toBe('25000.00');
    });

    it('posts each counterparty bucket on the correct side', function (BalanceBucket $bucket): void {
        $this->posting->post($this->rules->build(input(TransactionType::OpeningBalance, '1000', [
            'counterparty' => $this->party,
            'bucket' => $bucket,
        ])));

        // Positive either way: each account holds what its kind implies it should.
        expect(balanceOf($this->resolver->forBucket($bucket, $this->party, $this->egp)))->toBe('1000.00');
    })->with(BalanceBucket::cases());

    it('debits equity for a liability bucket and credits it for an asset', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::OpeningBalance, '1000', [
            'counterparty' => $this->party,
            'bucket' => BalanceBucket::CreditTrust,
        ])));

        $entry = LedgerEntry::query()
            ->where('ledger_account_id', $this->resolver->system(LedgerAccountSubkind::OpeningEquity, $this->egp)->id)
            ->sole();

        expect($entry->direction)->toBe(EntryDirection::Debit);
    });
});

describe('income and cost', function (): void {
    it('records a fee as income', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::Fee, '75')));

        expect(balanceOf($this->resolver->system(LedgerAccountSubkind::FeesIncome, $this->egp)))->toBe('75.00')
            ->and(balanceOf($this->resolver->forAccount($this->safe, $this->egp)))->toBe('75.00');
    });

    it('records an expense against cash', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::Expense, '120')));

        expect(balanceOf($this->resolver->system(LedgerAccountSubkind::Expense, $this->egp)))->toBe('120.00')
            ->and(balanceOf($this->resolver->forAccount($this->safe, $this->egp)))->toBe('-120.00');
    });

    // Section 7 forbids editing a balance; a correction is a transaction with a trail.
    it('records a balance adjustment against an equity account', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::BalanceAdjustment, '5')));

        expect(balanceOf($this->resolver->forAccount($this->safe, $this->egp)))->toBe('5.00')
            ->and(balanceOf($this->resolver->system(LedgerAccountSubkind::AdjustmentEquity, $this->egp)))->toBe('5.00');
    });
});

describe('types that are not built here', function (): void {
    it('refuses to build a currency exchange', function (): void {
        expect(fn () => $this->rules->build(input(TransactionType::CurrencyExchange, '100')))
            ->toThrow(DomainException::class, 'two amounts and a rate');
    });

    // A reversal mirrors real entries; recomputing it could round differently.
    it('refuses to build a reversal from an input', function (): void {
        expect(fn () => $this->rules->build(input(TransactionType::Reversal, '100')))
            ->toThrow(DomainException::class, 'PostingService::reverse()');
    });
});
