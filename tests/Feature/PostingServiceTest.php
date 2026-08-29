<?php

declare(strict_types=1);

use App\Domain\Ledger\EntryDraft;
use App\Domain\Ledger\Exceptions\InvalidPosting;
use App\Domain\Ledger\Exceptions\UnbalancedTransaction;
use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRequest;
use App\Domain\Ledger\PostingService;
use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Enums\EntryDirection;
use App\Enums\LedgerAccountSubkind;
use App\Enums\MovementMethod;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\LedgerBalance;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->usd = Currency::query()->where('code', 'USD')->sole();
    $this->egp = Currency::query()->where('code', 'EGP')->sole();

    $this->resolver = app(LedgerAccountResolver::class);
    $this->posting = app(PostingService::class);

    $this->safe = Account::factory()->create(['name' => 'Office safe']);
    $this->party = Counterparty::factory()->create(['name' => 'سالم التجريبي']);

    $this->cashEgp = $this->resolver->forAccount($this->safe, $this->egp);
    $this->cashUsd = $this->resolver->forAccount($this->safe, $this->usd);
    $this->creditEgp = $this->resolver->forCounterparty($this->party, $this->egp);
    $this->receivableEgp = $this->resolver->forCounterparty($this->party, $this->egp);
});

/** A credit deposit: money in, liability up. */
function creditDeposit(string $amount, ?string $key = null, TransactionStatus $status = TransactionStatus::Posted): PostingRequest
{
    $test = test();
    $money = $test->egp->money($amount);

    return new PostingRequest(
        type: TransactionType::In,
        occurredAt: now(),
        entries: [
            EntryDraft::debit($test->cashEgp, $money),
            EntryDraft::credit($test->creditEgp, $money),
        ],
        counterparty: $test->party,
        method: MovementMethod::Transfer,
        idempotencyKey: $key,
        status: $status,
    );
}

describe('the balancing invariant', function (): void {
    it('posts a transaction that balances', function (): void {
        $transaction = $this->posting->post(creditDeposit('581000'));

        expect($transaction->status)->toBe(TransactionStatus::Posted)
            ->and($transaction->entries)->toHaveCount(2)
            ->and($transaction->method)->toBe(MovementMethod::Transfer);
    });

    it('refuses a transaction whose sides do not agree', function (): void {
        expect(fn () => $this->posting->post(new PostingRequest(
            type: TransactionType::In,
            occurredAt: now(),
            entries: [
                EntryDraft::debit($this->cashEgp, $this->egp->money('100')),
                EntryDraft::credit($this->creditEgp, $this->egp->money('99')),
            ],
        )))->toThrow(UnbalancedTransaction::class, 'does not balance in EGP');
    });

    it('reports the difference so the mistake is findable', function (): void {
        expect(fn () => $this->posting->post(new PostingRequest(
            type: TransactionType::In,
            occurredAt: now(),
            entries: [
                EntryDraft::debit($this->cashEgp, $this->egp->money('100')),
                EntryDraft::credit($this->creditEgp, $this->egp->money('99')),
            ],
        )))->toThrow(UnbalancedTransaction::class, 'difference of 1.00');
    });

    // The heart of the design: each currency balances on its own, with no exchange
    // rate anywhere in the check. That is why a posted transaction cannot drift.
    it('balances each currency independently in a cross-currency posting', function (): void {
        $fxEgp = $this->resolver->system(LedgerAccountSubkind::FxPosition, $this->egp);
        $fxUsd = $this->resolver->system(LedgerAccountSubkind::FxPosition, $this->usd);

        $transaction = $this->posting->post(new PostingRequest(
            type: TransactionType::CurrencyExchange,
            occurredAt: now(),
            entries: [
                EntryDraft::debit($this->cashEgp, $this->egp->money('2574000')),
                EntryDraft::credit($fxEgp, $this->egp->money('2574000')),
                EntryDraft::debit($fxUsd, $this->usd->money('50000')),
                EntryDraft::credit($this->cashUsd, $this->usd->money('50000')),
            ],
        ));

        expect($transaction->entries)->toHaveCount(4);
    });

    it('refuses when one currency balances and another does not', function (): void {
        $fxEgp = $this->resolver->system(LedgerAccountSubkind::FxPosition, $this->egp);
        $fxUsd = $this->resolver->system(LedgerAccountSubkind::FxPosition, $this->usd);

        expect(fn () => $this->posting->post(new PostingRequest(
            type: TransactionType::CurrencyExchange,
            occurredAt: now(),
            entries: [
                EntryDraft::debit($this->cashEgp, $this->egp->money('2574000')),
                EntryDraft::credit($fxEgp, $this->egp->money('2574000')),
                EntryDraft::debit($fxUsd, $this->usd->money('50000')),
                EntryDraft::credit($this->cashUsd, $this->usd->money('49000')),
            ],
        )))->toThrow(UnbalancedTransaction::class, 'does not balance in USD');
    });

    it('refuses a posting with no entries', function (): void {
        expect(fn () => $this->posting->post(new PostingRequest(
            type: TransactionType::Deposit,
            occurredAt: now(),
            entries: [],
        )))->toThrow(InvalidPosting::class, 'at least one entry');
    });

    it('writes nothing at all when a posting is refused', function (): void {
        try {
            $this->posting->post(new PostingRequest(
                type: TransactionType::In,
                occurredAt: now(),
                entries: [
                    EntryDraft::debit($this->cashEgp, $this->egp->money('100')),
                    EntryDraft::credit($this->creditEgp, $this->egp->money('99')),
                ],
            ));
        } catch (UnbalancedTransaction) {
            // expected
        }

        expect(Transaction::query()->count())->toBe(0)
            ->and(LedgerEntry::query()->count())->toBe(0);
    });
});

describe('entry drafts', function (): void {
    it('refuses an amount in a currency the account does not hold', function (): void {
        expect(fn () => EntryDraft::debit($this->cashEgp, $this->usd->money('1')))
            ->toThrow(CurrencyMismatch::class);
    });

    // Direction carries the sign; a negative amount would say it twice.
    it('refuses a negative amount', function (): void {
        expect(fn () => EntryDraft::debit($this->cashEgp, $this->egp->money('-1')))
            ->toThrow(InvalidArgumentException::class, 'direction carries the sign');
    });

    it('refuses a zero amount', function (): void {
        expect(fn () => EntryDraft::credit($this->cashEgp, $this->egp->money('0')))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('balances', function (): void {
    it('increases an asset on debit and a liability on credit', function (): void {
        $this->posting->post(creditDeposit('581000'));

        $cash = LedgerBalance::query()->where('ledger_account_id', $this->cashEgp->id)->sole();
        $credit = LedgerBalance::query()->where('ledger_account_id', $this->creditEgp->id)->sole();

        // Both positive: each account holds what its kind implies it should.
        expect($cash->confirmed()->toDisplayString())->toBe('581000.00')
            ->and($credit->confirmed()->toDisplayString())->toBe('581000.00');
    });

    // The nine tranches from the real statement.
    it('accumulates across many postings exactly', function (): void {
        foreach (['581000', '436540', '500000', '560000', '450000', '275000', '463330', '341670', '350000'] as $amount) {
            $this->posting->post(creditDeposit($amount));
        }

        $credit = LedgerBalance::query()->where('ledger_account_id', $this->creditEgp->id)->sole();

        expect($credit->confirmed()->toDisplayString())->toBe('3957540.00');
    });

    it('decreases a liability when it is settled', function (): void {
        $this->posting->post(creditDeposit('3957540'));

        $this->posting->post(new PostingRequest(
            type: TransactionType::Out,
            occurredAt: now(),
            entries: [
                EntryDraft::debit($this->creditEgp, $this->egp->money('2574000')),
                EntryDraft::credit($this->cashEgp, $this->egp->money('2574000')),
            ],
            counterparty: $this->party,
        ));

        $credit = LedgerBalance::query()->where('ledger_account_id', $this->creditEgp->id)->sole();

        // Matches the statement exactly.
        expect($credit->confirmed()->toDisplayString())->toBe('1383540.00');
    });

    it('lets a liability go negative, as decided', function (): void {
        $this->posting->post(creditDeposit('100'));

        $this->posting->post(new PostingRequest(
            type: TransactionType::Out,
            occurredAt: now(),
            entries: [
                EntryDraft::debit($this->creditEgp, $this->egp->money('150')),
                EntryDraft::credit($this->cashEgp, $this->egp->money('150')),
            ],
        ));

        $credit = LedgerBalance::query()->where('ledger_account_id', $this->creditEgp->id)->sole();

        expect($credit->confirmed()->toDisplayString())->toBe('-50.00');
    });

    it('keeps each currency on its own account', function (): void {
        $this->posting->post(creditDeposit('100'));

        expect(LedgerBalance::query()->where('ledger_account_id', $this->cashUsd->id)->exists())->toBeFalse();
    });
});

describe('pending and available', function (): void {
    // Money somebody has promised is not money you can spend.
    it('holds back a pending outflow from the available balance', function (): void {
        $this->posting->post(creditDeposit('1000'));

        $this->posting->post(new PostingRequest(
            type: TransactionType::Out,
            occurredAt: now(),
            entries: [
                EntryDraft::debit($this->receivableEgp, $this->egp->money('300')),
                EntryDraft::credit($this->cashEgp, $this->egp->money('300')),
            ],
            status: TransactionStatus::Pending,
        ));

        $cash = LedgerBalance::query()->where('ledger_account_id', $this->cashEgp->id)->sole();

        expect($cash->confirmed()->toDisplayString())->toBe('1000.00')
            ->and($cash->available()->toDisplayString())->toBe('700.00');
    });

    it('does not count a pending inflow as available', function (): void {
        $this->posting->post(creditDeposit('500', null, TransactionStatus::Pending));

        $cash = LedgerBalance::query()->where('ledger_account_id', $this->cashEgp->id)->sole();

        expect($cash->confirmed()->isZero())->toBeTrue()
            ->and($cash->available()->isZero())->toBeTrue();
    });
});

describe('duplicate submission', function (): void {
    // The double-clicked exchange is a real failure mode; a retry must be harmless.
    it('posts once for a repeated idempotency key', function (): void {
        $first = $this->posting->post(creditDeposit('581000', 'abc-123'));
        $second = $this->posting->post(creditDeposit('581000', 'abc-123'));

        expect($second->id)->toBe($first->id)
            ->and(Transaction::query()->count())->toBe(1)
            ->and(LedgerEntry::query()->count())->toBe(2);
    });

    it('leaves the balance untouched by the replay', function (): void {
        $this->posting->post(creditDeposit('581000', 'abc-123'));
        $this->posting->post(creditDeposit('581000', 'abc-123'));

        $credit = LedgerBalance::query()->where('ledger_account_id', $this->creditEgp->id)->sole();

        expect($credit->confirmed()->toDisplayString())->toBe('581000.00');
    });

    it('treats postings without a key as distinct', function (): void {
        $this->posting->post(creditDeposit('100'));
        $this->posting->post(creditDeposit('100'));

        expect(Transaction::query()->count())->toBe(2);
    });

    // Belt and braces: a genuine race loses at the database, not in PHP.
    it('enforces uniqueness at the database too', function (): void {
        $this->posting->post(creditDeposit('100', 'dup'));

        expect(fn () => DB::table('transactions')->insert([
            'type' => 'deposit', 'status' => 'posted', 'occurred_at' => now(),
            'idempotency_key' => 'dup', 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});

describe('reversal', function (): void {
    it('posts the mirror image and marks the original reversed', function (): void {
        $original = $this->posting->post(creditDeposit('581000'));

        $reversal = $this->posting->reverse($original, 'Entered against the wrong party');

        expect($original->fresh()?->status)->toBe(TransactionStatus::Reversed)
            ->and($reversal->type)->toBe(TransactionType::Reversal)
            ->and($reversal->reversal_of_transaction_id)->toBe($original->id)
            ->and($reversal->description)->toBe('Entered against the wrong party');
    });

    it('returns the balance to where it started', function (): void {
        $original = $this->posting->post(creditDeposit('581000'));
        $this->posting->reverse($original);

        $credit = LedgerBalance::query()->where('ledger_account_id', $this->creditEgp->id)->sole();

        expect($credit->confirmed()->isZero())->toBeTrue();
    });

    // Nothing is edited and nothing is deleted; both sides remain readable.
    it('keeps every original entry', function (): void {
        $original = $this->posting->post(creditDeposit('581000'));
        $this->posting->reverse($original);

        expect($original->fresh()?->entries()->count())->toBe(2)
            ->and(LedgerEntry::query()->count())->toBe(4);
    });

    it('flips each direction and keeps each amount', function (): void {
        $original = $this->posting->post(creditDeposit('581000'));
        $reversal = $this->posting->reverse($original);

        $originalCash = $original->entries()->where('ledger_account_id', $this->cashEgp->id)->sole();
        $reversalCash = $reversal->entries()->where('ledger_account_id', $this->cashEgp->id)->sole();

        expect($originalCash->direction)->toBe(EntryDirection::Debit)
            ->and($reversalCash->direction)->toBe(EntryDirection::Credit)
            ->and($reversalCash->amount->toDisplayString())->toBe($originalCash->amount->toDisplayString());
    });

    it('refuses to reverse the same transaction twice', function (): void {
        $original = $this->posting->post(creditDeposit('100'));
        $this->posting->reverse($original);

        expect(fn () => $this->posting->reverse($original->fresh()))
            ->toThrow(InvalidPosting::class, 'already been reversed');
    });

    // Reversing a reversal is not an undo; it is a third transaction.
    it('allows a reversal to itself be reversed', function (): void {
        $original = $this->posting->post(creditDeposit('100'));
        $reversal = $this->posting->reverse($original);

        $third = $this->posting->reverse($reversal);

        expect(Transaction::query()->count())->toBe(3)
            ->and($third->reversal_of_transaction_id)->toBe($reversal->id);

        $credit = LedgerBalance::query()->where('ledger_account_id', $this->creditEgp->id)->sole();

        expect($credit->confirmed()->toDisplayString())->toBe('100.00');
    });

    it('carries its own date rather than rewriting the original period', function (): void {
        $original = $this->posting->post(creditDeposit('100'));
        $when = now()->addMonth();

        $reversal = $this->posting->reverse($original, null, $when);

        expect($reversal->occurred_at->toDateString())->toBe($when->toDateString())
            ->and($original->fresh()?->occurred_at->toDateString())->not->toBe($when->toDateString());
    });
});

describe('append-only entries', function (): void {
    it('refuses to update an entry through the model', function (): void {
        $entry = $this->posting->post(creditDeposit('100'))->entries()->first();

        expect(fn () => $entry?->update(['amount' => '1']))
            ->toThrow(RuntimeException::class, 'append-only');
    });

    it('refuses to delete an entry through the model', function (): void {
        $entry = $this->posting->post(creditDeposit('100'))->entries()->first();

        expect(fn () => $entry?->delete())->toThrow(RuntimeException::class, 'append-only');
    });

    // The guarantee: raw SQL bypassing Eloquent entirely is still refused.
    it('refuses an update issued as raw SQL', function (): void {
        $entry = $this->posting->post(creditDeposit('100'))->entries()->first();

        expect(fn () => DB::table('ledger_entries')->where('id', $entry?->id)->update(['amount' => '1']))
            ->toThrow(QueryException::class, 'append-only');
    });

    it('refuses a delete issued as raw SQL', function (): void {
        $entry = $this->posting->post(creditDeposit('100'))->entries()->first();

        expect(fn () => DB::table('ledger_entries')->where('id', $entry?->id)->delete())
            ->toThrow(QueryException::class, 'append-only');
    });

    it('refuses a non-positive amount at the database', function (): void {
        expect(fn () => DB::table('ledger_entries')->insert([
            'transaction_id' => $this->posting->post(creditDeposit('100'))->id,
            'ledger_account_id' => $this->cashEgp->id,
            'currency_id' => $this->egp->id,
            'direction' => 'debit',
            'amount' => 0,
            'sequence' => 9,
            'occurred_at' => now(),
            'created_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});

describe('attribution and audit', function (): void {
    it('records who posted it', function (): void {
        $user = User::factory()->create();
        $this->actingAs($user);

        $transaction = $this->posting->post(creditDeposit('100'));

        expect($transaction->created_by)->toBe($user->id)
            ->and($transaction->posted_by)->toBe($user->id)
            ->and($transaction->posted_at)->not->toBeNull();
    });

    it('leaves a pending transaction unposted by anyone', function (): void {
        $this->actingAs(User::factory()->create());

        $transaction = $this->posting->post(creditDeposit('100', null, TransactionStatus::Pending));

        expect($transaction->posted_by)->toBeNull()
            ->and($transaction->posted_at)->toBeNull()
            ->and($transaction->created_by)->not->toBeNull();
    });

    it('writes the transaction to the audit trail', function (): void {
        $transaction = $this->posting->post(creditDeposit('100'));

        expect($transaction->auditLogs()->where('event', 'created')->exists())->toBeTrue();
    });

    it('keeps the deduplication token out of the audit trail', function (): void {
        $transaction = $this->posting->post(creditDeposit('100', 'secret-key'));

        $entry = $transaction->auditLogs()->where('event', 'created')->sole();

        expect($entry->new_values)->not->toHaveKey('idempotency_key');
    });
});
