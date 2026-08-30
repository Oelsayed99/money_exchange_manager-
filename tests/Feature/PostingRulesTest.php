<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Enums\EntryDirection;
use App\Enums\LedgerAccountSubkind;
use App\Enums\LedgerOwnerType;
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
    $this->eur = Currency::query()->where('code', 'EUR')->sole();

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
        cashCurrency: $extra['cashCurrency'] ?? null,
        cashAmount: $extra['cashAmount'] ?? null,
        rate: $extra['rate'] ?? null,
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
        expect(fn () => $this->rules->build(input(TransactionType::In, '100')))
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

describe('client movements', function (): void {
    // The whole relationship, in one signed figure. Out puts them into debt to us; in
    // works it off and then past it. See ADR 0032.
    it('raises the balance when money goes out to them', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::Out, '500', [
            'counterparty' => $this->party,
        ])));

        expect(balanceOf($this->resolver->forCounterparty($this->party, $this->egp)))->toBe('500.00')
            ->and(balanceOf($this->resolver->forAccount($this->safe, $this->egp)))->toBe('-500.00');
    });

    it('lowers it when money comes in from them', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::Out, '500', ['counterparty' => $this->party])));
        $this->posting->post($this->rules->build(input(TransactionType::In, '200', ['counterparty' => $this->party])));

        expect(balanceOf($this->resolver->forCounterparty($this->party, $this->egp)))->toBe('300.00');
    });

    // What the four buckets used to keep apart, said with a sign instead.
    it('goes negative when they have given us more than we gave them', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::In, '899510', ['counterparty' => $this->party])));
        $this->posting->post($this->rules->build(input(TransactionType::Out, '14890', ['counterparty' => $this->party])));

        expect(balanceOf($this->resolver->forCounterparty($this->party, $this->egp)))->toBe('-884620.00');
    });

    it('uses one account per party per currency, not four', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::In, '100', ['counterparty' => $this->party])));

        $accounts = LedgerAccount::query()
            ->where('owner_type', LedgerOwnerType::Counterparty->value)
            ->where('owner_id', $this->party->id)
            ->get();

        expect($accounts)->toHaveCount(1)
            ->and($accounts->first()->subkind)->toBe(LedgerAccountSubkind::ClientAccount);
    });
});

/**
 * Taking money in one currency and recording it against the client in another.
 *
 * "I got 10,000 dollars but I am booking it as pounds at 50.85." The dollars really
 * arrived and the client's account really moved by 508,500 — two facts in two
 * currencies, joined through the clearing accounts exactly as an exchange joins its
 * legs, so each currency still balances on its own.
 */
describe('recording in another currency', function (): void {
    it('moves the cash in one currency and the client in the other', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::In, '508500', [
            'counterparty' => $this->party,
            'cashCurrency' => $this->usd,
            'cashAmount' => $this->usd->money('10000'),
            'rate' => '50.85',
        ])));

        expect(balanceOf($this->resolver->forAccount($this->safe, $this->usd)))->toBe('10000.00')
            ->and(balanceOf($this->resolver->forCounterparty($this->party, $this->egp)))->toBe('-508500.00');
    });

    it('balances in both currencies without an exchange rate in the check', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::In, '508500', [
            'counterparty' => $this->party,
            'cashCurrency' => $this->usd,
            'cashAmount' => $this->usd->money('10000'),
            'rate' => '50.85',
        ])));

        $this->artisan('ledger:verify --transactions')->assertExitCode(0);
    });

    it('does the same going out', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::Out, '1000000', [
            'counterparty' => $this->party,
            'cashCurrency' => $this->eur,
            'cashAmount' => $this->eur->money('17182.13'),
            'rate' => '58.20',
        ])));

        expect(balanceOf($this->resolver->forCounterparty($this->party, $this->egp)))->toBe('1000000.00')
            ->and(balanceOf($this->resolver->forAccount($this->safe, $this->eur)))->toBe('-17182.13');
    });

    // Both figures reach the transaction list, so a row reads "10,000 USD in,
    // 508,500 EGP against the account" rather than picking one and hiding the other.
    it('records both amounts as legs', function (): void {
        $transaction = $this->posting->post($this->rules->build(input(TransactionType::In, '508500', [
            'counterparty' => $this->party,
            'cashCurrency' => $this->usd,
            'cashAmount' => $this->usd->money('10000'),
            'rate' => '50.85',
        ])));

        expect($transaction->legs)->toHaveCount(2)
            ->and($transaction->legs->pluck('currency_id')->all())
            ->toBe([$this->usd->id, $this->egp->id]);
    });

    it('records one leg when nothing was converted', function (): void {
        $transaction = $this->posting->post($this->rules->build(input(TransactionType::In, '5000', [
            'counterparty' => $this->party,
        ])));

        expect($transaction->legs)->toHaveCount(1);
    });
});

describe('the real statement', function (): void {
    it('reproduces the balance through nine deposits and one settlement', function (): void {
        foreach (['581000', '436540', '500000', '560000', '450000', '275000', '463330', '341670', '350000'] as $amount) {
            $this->posting->post($this->rules->build(input(TransactionType::In, $amount, [
                'counterparty' => $this->party,
                'method' => MovementMethod::Transfer,
            ])));
        }

        // Everything they handed over and nothing back: we are holding all of it.
        expect(balanceOf($this->resolver->forCounterparty($this->party, $this->egp)))->toBe('-3957540.00');

        // 50,000 USD at 51.48 — the EGP value going back out to them.
        $this->posting->post($this->rules->build(input(TransactionType::Out, '2574000', [
            'counterparty' => $this->party,
        ])));

        expect(balanceOf($this->resolver->forCounterparty($this->party, $this->egp)))->toBe('-1383540.00');
    });

    it('records how the money arrived', function (): void {
        $transaction = $this->posting->post($this->rules->build(input(TransactionType::In, '950000', [
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

    // The one signed amount in the system: an opening position can start either way.
    it('opens a debt to us with a positive figure', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::OpeningBalance, '1000', [
            'counterparty' => $this->party,
        ])));

        expect(balanceOf($this->resolver->forCounterparty($this->party, $this->egp)))->toBe('1000.00');
    });

    it('opens one the other way with a negative figure', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::OpeningBalance, '-884620', [
            'counterparty' => $this->party,
        ])));

        expect(balanceOf($this->resolver->forCounterparty($this->party, $this->egp)))->toBe('-884620.00');
    });

    it('credits equity when the party owes us and debits it when we owe them', function (): void {
        $this->posting->post($this->rules->build(input(TransactionType::OpeningBalance, '-1000', [
            'counterparty' => $this->party,
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
