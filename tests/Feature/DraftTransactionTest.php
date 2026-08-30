<?php

declare(strict_types=1);

use App\Domain\Ledger\Exceptions\InvalidPosting;
use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Money\CurrencyRegistry;
use App\Enums\MovementMethod;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\LedgerAccount;
use App\Models\LedgerBalance;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\CurrencySeeder;

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

    $this->input = fn (string $amount = '581000'): TransactionInput => new TransactionInput(
        type: TransactionType::In,
        currency: $this->egp,
        amount: $this->egp->money($amount),
        occurredAt: now(),
        account: $this->safe,
        counterparty: $this->party,
        method: MovementMethod::Transfer,
        reference: 'REF-1',
    );

    $this->creditAccount = fn () => $this->resolver->forCounterparty($this->party, $this->egp);
});

describe('creating a draft', function (): void {
    // The defining property: a draft has no entries, so it cannot affect a balance.
    it('writes no ledger entries and moves no balance', function (): void {
        $draft = $this->posting->draft(($this->input)());

        expect($draft->status)->toBe(TransactionStatus::Draft)
            ->and(LedgerEntry::query()->count())->toBe(0)
            ->and(LedgerBalance::query()->count())->toBe(0);
    });

    it('keeps what it needs to be posted later', function (): void {
        $draft = $this->posting->draft(($this->input)());

        expect($draft->draft_payload['amount'])->toBe('581000.0000000000')
            ->and($draft->draft_payload['counterparty_id'])->toBe($this->party->id)
            ->and($draft->draft_payload['account_id'])->toBe($this->safe->id)
            ->and($draft->method)->toBe(MovementMethod::Transfer);
    });

    // An input that could never post should fail while somebody is still looking at
    // it, not days later when it is committed.
    it('refuses a draft that could never be posted', function (): void {
        expect(fn () => $this->posting->draft(new TransactionInput(
            type: TransactionType::In,
            currency: $this->egp,
            amount: $this->egp->money('100'),
            occurredAt: now(),
            account: $this->safe,
            // No counterparty: a credit deposit has nobody to owe.
        )))->toThrow(InvalidArgumentException::class, 'needs a counterparty');

        expect(Transaction::query()->count())->toBe(0);
    });
});

describe('committing a draft', function (): void {
    it('posts the entries and moves the balance', function (): void {
        $draft = $this->posting->draft(($this->input)());

        $posted = $this->posting->commit($draft);

        expect($posted->status)->toBe(TransactionStatus::Posted)
            ->and($posted->entries)->toHaveCount(2)
            ->and(LedgerBalance::query()->where('ledger_account_id', ($this->creditAccount)()->id)->sole()
                ->confirmed()->toDisplayString())->toBe('-581000.00');
    });

    // Anything already referring to the draft still refers to the same thing.
    it('keeps the same transaction row', function (): void {
        $draft = $this->posting->draft(($this->input)());
        $id = $draft->id;

        $posted = $this->posting->commit($draft);

        expect($posted->id)->toBe($id)
            ->and(Transaction::query()->count())->toBe(1);
    });

    it('clears the stored inputs once they have been used', function (): void {
        $draft = $this->posting->draft(($this->input)());

        expect($this->posting->commit($draft)->draft_payload)->toBeNull();
    });

    it('records who committed it', function (): void {
        $user = User::factory()->create();
        $draft = $this->posting->draft(($this->input)());

        $this->actingAs($user);
        $posted = $this->posting->commit($draft);

        expect($posted->posted_by)->toBe($user->id)
            ->and($posted->posted_at)->not->toBeNull();
    });

    it('can commit as pending rather than posted', function (): void {
        $draft = $this->posting->draft(($this->input)());

        $pending = $this->posting->commit($draft, TransactionStatus::Pending);

        expect($pending->status)->toBe(TransactionStatus::Pending)
            ->and($pending->posted_at)->toBeNull()
            // Pending entries exist but do not count as confirmed.
            ->and(LedgerBalance::query()->where('ledger_account_id', ($this->creditAccount)()->id)->sole()
                ->confirmed()->isZero())->toBeTrue();
    });

    it('refuses to commit something already posted', function (): void {
        $posted = $this->posting->commit($this->posting->draft(($this->input)()));

        expect(fn () => $this->posting->commit($posted))
            ->toThrow(InvalidPosting::class, 'not a draft');
    });

    it('refuses to commit a draft into a draft', function (): void {
        $draft = $this->posting->draft(($this->input)());

        expect(fn () => $this->posting->commit($draft, TransactionStatus::Draft))
            ->toThrow(InvalidPosting::class, 'not leaving it a draft');
    });

    // Validating a draft runs the rules, which resolves the accounts it would use.
    // The trade-off is documented on PostingService::draft: a discarded draft can leave
    // an account behind with no entries, in exchange for catching a malformed
    // transaction while somebody is still entering it.
    it('resolves the accounts it will need when the draft is validated', function (): void {
        $this->posting->draft(($this->input)());

        expect(LedgerAccount::query()->count())->toBe(2)
            ->and(LedgerEntry::query()->count())->toBe(0);
    });

    it('leaves an account with a zero balance when a draft is discarded', function (): void {
        $this->posting->discardDraft($this->posting->draft(($this->input)()));

        expect(LedgerAccount::query()->count())->toBe(2)
            ->and(LedgerBalance::query()->count())->toBe(0);

        // Harmless: an account with no entries reports zero, and verify stays clean.
        $this->artisan('ledger:verify')->assertExitCode(0);
    });
});

describe('discarding a draft', function (): void {
    // The only deletion the system permits, and only because it never touched the ledger.
    it('deletes a draft outright', function (): void {
        $draft = $this->posting->draft(($this->input)());

        $this->posting->discardDraft($draft);

        expect(Transaction::query()->count())->toBe(0)
            ->and(LedgerEntry::query()->count())->toBe(0);
    });

    it('refuses to discard anything that has been posted', function (): void {
        $posted = $this->posting->commit($this->posting->draft(($this->input)()));

        expect(fn () => $this->posting->discardDraft($posted))
            ->toThrow(InvalidPosting::class, 'Reverse it instead');

        expect(Transaction::query()->count())->toBe(1);
    });

    it('refuses to discard a pending transaction', function (): void {
        $pending = $this->posting->commit($this->posting->draft(($this->input)()), TransactionStatus::Pending);

        expect(fn () => $this->posting->discardDraft($pending))
            ->toThrow(InvalidPosting::class, 'cannot be discarded');
    });
});

describe('reversal and drafts', function (): void {
    it('refuses to reverse a draft, which has nothing to reverse', function (): void {
        $draft = $this->posting->draft(($this->input)());

        expect(fn () => $this->posting->reverse($draft))
            ->toThrow(InvalidPosting::class, 'nothing to reverse');
    });
});

describe('integrity', function (): void {
    it('leaves the ledger verifiable through the whole lifecycle', function (): void {
        $this->posting->commit($this->posting->draft(($this->input)('1000')));
        $this->posting->draft(($this->input)('500'));
        $this->posting->discardDraft($this->posting->draft(($this->input)('250')));

        $this->artisan('ledger:verify --transactions')->assertExitCode(0);

        expect(LedgerBalance::query()->where('ledger_account_id', ($this->creditAccount)()->id)->sole()
            ->confirmed()->toDisplayString())->toBe('-1000.00');
    });
});
