<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Money\CurrencyRegistry;
use App\Domain\Reconciliation\ReconciliationService;
use App\Enums\ReconciliationStatus;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Currency;
use App\Models\LedgerEntry;
use App\Models\Reconciliation;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();

    $this->egp = Currency::query()->where('code', 'EGP')->sole();
    $this->safe = Account::factory()->create(['name' => 'Main safe']);

    $this->service = app(ReconciliationService::class);
    $this->rules = app(PostingRules::class);
    $this->posting = app(PostingService::class);

    $this->operator = User::factory()->create();
    $this->operator->assignRole(Role::Operator->value);

    $this->viewer = User::factory()->create();
    $this->viewer->assignRole(Role::Viewer->value);
});

function deposited(string $amount, string $date = '2026-06-10'): void
{
    $test = test();

    $test->posting->post($test->rules->build(new TransactionInput(
        type: TransactionType::Deposit,
        currency: $test->egp,
        amount: $test->egp->money($amount),
        occurredAt: new DateTimeImmutable($date),
        account: $test->safe,
    )));
}

function count_(string $amount, string $asOf = '2026-06-30'): Reconciliation
{
    $test = test();

    return $test->service->record(
        $test->safe,
        $test->egp,
        Carbon::parse($asOf),
        $test->egp->money($amount),
        $test->operator,
    );
}

describe('the balance as of a day', function (): void {
    // The balance cache knows about now. A reconciliation asks about a day, and the
    // two differ the moment anything is backdated — which is normal.
    it('counts only what had happened by then', function (): void {
        deposited('1000', '2026-06-01');
        deposited('500', '2026-07-15');

        expect($this->service->ledgerBalanceAsOf($this->safe, $this->egp, Carbon::parse('2026-06-30'))->toDisplayString())
            ->toBe('1000.00');
    });

    it('includes everything on the closing day itself', function (): void {
        deposited('1000', '2026-06-30');

        expect($this->service->ledgerBalanceAsOf($this->safe, $this->egp, Carbon::parse('2026-06-30'))->toDisplayString())
            ->toBe('1000.00');
    });

    it('is zero for an account nothing has touched', function (): void {
        expect($this->service->ledgerBalanceAsOf($this->safe, $this->egp, Carbon::parse('2026-06-30'))->isZero())->toBeTrue();
    });
});

describe('recording a count', function (): void {
    it('balances when the count agrees', function (): void {
        deposited('1000', '2026-06-01');

        $record = count_('1000');

        expect($record->status)->toBe(ReconciliationStatus::Balanced)
            ->and($record->difference->isZero())->toBeTrue();
    });

    it('opens a difference when more was found than expected', function (): void {
        deposited('1000', '2026-06-01');

        $record = count_('1200');

        expect($record->status)->toBe(ReconciliationStatus::Open)
            ->and($record->difference->toDisplayString())->toBe('200.00')
            ->and($record->isSurplus())->toBeTrue();
    });

    it('opens a difference when less was found', function (): void {
        deposited('1000', '2026-06-01');

        $record = count_('900');

        expect($record->difference->toDisplayString())->toBe('-100.00')
            ->and($record->isSurplus())->toBeFalse();
    });

    // Recording a discrepancy must not move money. Correcting it is a posting, and a
    // posting is a deliberate separate act.
    it('writes nothing to the ledger', function (): void {
        deposited('1000', '2026-06-01');

        $before = LedgerEntry::query()->count();
        count_('1200');

        expect(LedgerEntry::query()->count())->toBe($before);
    });

    it('refuses a count in the wrong currency', function (): void {
        $usd = Currency::query()->where('code', 'USD')->sole();

        expect(fn () => $this->service->record($this->safe, $this->egp, Carbon::parse('2026-06-30'), $usd->money('100')))
            ->toThrow(DomainException::class, 'cannot reconcile');
    });

    it('refuses a count dated in the future', function (): void {
        expect(fn () => count_('1000', now()->addDay()->toDateString()))
            ->toThrow(DomainException::class, 'has not');
    });
});

/*
 * A reconciliation is a record of what was found on a day. The database enforces this
 * with a trigger; the model refuses first so the failure is legible.
 */
describe('the figures are frozen', function (): void {
    it('refuses to have its count edited', function (): void {
        $record = count_('1000');

        expect(fn () => $record->update(['counted_amount' => '9999']))
            ->toThrow(RuntimeException::class, 'cannot be edited');
    });

    it('refuses to have its date moved', function (): void {
        $record = count_('1000');

        expect(fn () => $record->update(['as_of' => '2026-01-01']))
            ->toThrow(RuntimeException::class, 'cannot be edited');
    });

    // The model guard could be bypassed; the database one cannot.
    it('is refused by the database even when the model is bypassed', function (): void {
        $record = count_('1000');

        expect(fn () => DB::table('reconciliations')->where('id', $record->id)->update(['counted_amount' => '9999']))
            ->toThrow(QueryException::class);
    });

    it('still allows the explanation to be added', function (): void {
        deposited('1000', '2026-06-01');
        $record = count_('1200');

        $resolved = $this->service->resolve($record, 'Cash float not yet posted', $this->operator);

        expect($resolved->status)->toBe(ReconciliationStatus::Resolved)
            ->and($resolved->resolution)->toBe('Cash float not yet posted');
    });
});

describe('explaining a difference', function (): void {
    it('refuses to explain a reconciliation that balanced', function (): void {
        deposited('1000', '2026-06-01');
        $record = count_('1000');

        expect(fn () => $this->service->resolve($record, 'nothing to say', $this->operator))
            ->toThrow(DomainException::class, 'no difference to explain');
    });

    it('refuses a blank explanation', function (): void {
        deposited('1000', '2026-06-01');
        $record = count_('1200');

        expect(fn () => $this->service->resolve($record, '   ', $this->operator))
            ->toThrow(DomainException::class, 'has to say something');
    });

    it('records who explained it and when', function (): void {
        deposited('1000', '2026-06-01');
        $record = $this->service->resolve(count_('1200'), 'Miscount', $this->operator);

        expect($record->resolved_by)->toBe($this->operator->id)
            ->and($record->resolved_at)->not->toBeNull();
    });
});

/*
 * The reason the ledger figure is stored rather than recomputed on read.
 */
describe('drift', function (): void {
    it('is nothing when the ledger has not moved', function (): void {
        deposited('1000', '2026-06-01');

        expect($this->service->drift(count_('1000'))->isZero())->toBeTrue();
    });

    // A reconciliation signed off on 30 June no longer describes the ledger once
    // somebody posts a 15 June entry. Recomputing on read would hide that; storing the
    // figure is what makes it visible.
    it('appears when something is backdated after the count', function (): void {
        deposited('1000', '2026-06-01');
        $record = count_('1000');

        deposited('250', '2026-06-15');

        expect($this->service->drift($record)->toDisplayString())->toBe('250.00');
    });

    it('ignores entries dated after the count', function (): void {
        deposited('1000', '2026-06-01');
        $record = count_('1000');

        deposited('250', '2026-07-15');

        expect($this->service->drift($record)->isZero())->toBeTrue();
    });
});

/*
 * The batched drift query duplicates arithmetic the single-row version reads from the
 * account's kind. Duplication that cannot be avoided can at least be watched.
 */
describe('drift in bulk', function (): void {
    it('agrees with the one-at-a-time answer, row for row', function (): void {
        deposited('1000', '2026-06-01');
        $first = count_('1000', '2026-06-30');

        deposited('400', '2026-07-02');
        $second = count_('1500', '2026-07-31');

        // Backdated into both periods, so both reconciliations have moved.
        deposited('250', '2026-06-15');

        $batch = $this->service->driftFor(Reconciliation::query()->get());

        foreach ([$first, $second] as $record) {
            $single = $this->service->drift($record->refresh());

            expect(($batch[$record->id] ?? null)?->toDisplayString() ?? '0.00')
                ->toBe($single->isZero() ? '0.00' : $single->toDisplayString());
        }
    });

    it('says nothing for a reconciliation the ledger has not moved past', function (): void {
        deposited('1000', '2026-06-01');
        $record = count_('1000');

        expect($this->service->driftFor(Reconciliation::query()->get()))->not->toHaveKey($record->id);
    });

    // One query for the drift, plus the currency registry's single load. What matters
    // is that neither grows: this used to be one query per row.
    it('asks the database the same number of times however many rows there are', function (): void {
        deposited('1000', '2026-06-01');

        $count = function (): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });

            $this->service->driftFor(Reconciliation::query()->get());

            return $queries;
        };

        $this->service->record($this->safe, $this->egp, Carbon::parse('2026-06-02'), $this->egp->money('999'));
        $one = $count();

        foreach (range(3, 12) as $day) {
            $this->service->record(
                $this->safe,
                $this->egp,
                Carbon::parse('2026-06-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT)),
                $this->egp->money('999'),
            );
        }

        expect($count())->toBe($one)
            ->and(Reconciliation::query()->count())->toBe(11);
    });
});

describe('the screen', function (): void {
    it('requires authentication', function (): void {
        $this->get('/reconciliations')->assertRedirect('/login');
    });

    it('lets a viewer read but not record', function (): void {
        $this->actingAs($this->viewer)->get('/reconciliations')->assertOk();

        $this->actingAs($this->viewer)->post('/reconciliations', [
            'account_id' => $this->safe->id,
            'currency_id' => $this->egp->id,
            'as_of' => '2026-06-30',
            'counted_amount' => '1000',
        ])->assertForbidden();
    });

    it('records a count', function (): void {
        deposited('1000', '2026-06-01');

        $this->actingAs($this->operator)->post('/reconciliations', [
            'account_id' => $this->safe->id,
            'currency_id' => $this->egp->id,
            'as_of' => '2026-06-30',
            'counted_amount' => '1200',
        ])->assertRedirect('/reconciliations');

        expect(Reconciliation::query()->sole()->difference->toDisplayString())->toBe('200.00');
    });

    it('refuses a second count for the same account, currency and day', function (): void {
        count_('1000');

        $this->actingAs($this->operator)->post('/reconciliations', [
            'account_id' => $this->safe->id,
            'currency_id' => $this->egp->id,
            'as_of' => '2026-06-30',
            'counted_amount' => '1100',
        ])->assertSessionHasErrors('as_of');

        expect(Reconciliation::query()->count())->toBe(1);
    });

    it('rejects a count that is not a plain decimal', function (string $amount): void {
        $this->actingAs($this->operator)->post('/reconciliations', [
            'account_id' => $this->safe->id,
            'currency_id' => $this->egp->id,
            'as_of' => '2026-06-30',
            'counted_amount' => $amount,
        ])->assertSessionHasErrors('counted_amount');
    })->with(['1e5', '1,000', 'abc']);

    it('rejects a count dated in the future', function (): void {
        $this->actingAs($this->operator)->post('/reconciliations', [
            'account_id' => $this->safe->id,
            'currency_id' => $this->egp->id,
            'as_of' => now()->addWeek()->toDateString(),
            'counted_amount' => '1000',
        ])->assertSessionHasErrors('as_of');
    });

    // Asked for on demand rather than prefilled: a figure sitting in the box invites
    // agreement, and a reconciliation that agrees by suggestion has checked nothing.
    it('tells you what the ledger says only when asked', function (): void {
        deposited('1000', '2026-06-01');

        $this->actingAs($this->operator)->postJson('/reconciliations/expected', [
            'account_id' => $this->safe->id,
            'currency_id' => $this->egp->id,
            'as_of' => '2026-06-30',
        ])->assertOk()->assertJsonPath('ledger_amount.amount', '1000.00');
    });

    it('explains a difference through the screen', function (): void {
        deposited('1000', '2026-06-01');
        $record = count_('1200');

        $this->actingAs($this->operator)
            ->post("/reconciliations/{$record->id}/resolve", ['resolution' => 'Float not posted'])
            ->assertSessionHasNoErrors();

        expect($record->refresh()->status)->toBe(ReconciliationStatus::Resolved);
    });

    it('shows the drift on a reconciliation the ledger has moved past', function (): void {
        deposited('1000', '2026-06-01');
        count_('1000');
        deposited('250', '2026-06-15');

        $props = $this->actingAs($this->operator)->get('/reconciliations')->viewData('page')['props'];

        expect($props['reconciliations'][0]['drift']['amount'])->toBe('250.00');
    });

    // Risk R1 once more.
    it('sends every amount as a string', function (): void {
        deposited('1000', '2026-06-01');
        count_('1200');

        $props = $this->actingAs($this->operator)->get('/reconciliations')->viewData('page')['props'];

        expect($props['reconciliations'][0]['counted']['amount'])->toBe('1200.00')
            ->and($props['reconciliations'][0]['difference']['amount'])->toBe('200.00');
    });
});
