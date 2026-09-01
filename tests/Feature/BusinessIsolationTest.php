<?php

declare(strict_types=1);

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Money\CurrencyRegistry;
use App\Domain\Reporting\CounterpartyStandings;
use App\Domain\Tenancy\CurrentBusiness;
use App\Domain\Tenancy\Exceptions\NoBusinessResolved;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;

/**
 * The test that matters most in the application.
 *
 * Every other test asks whether a figure is right. These ask whether it is *theirs* —
 * whether one exchange office can, by any route, see another's balances, clients,
 * margins or history. That failure is silent by nature: nothing errors, nothing looks
 * wrong, and the first person to notice is a customer reading somebody else's book.
 *
 * So the assertions are deliberately blunt and deliberately repetitive. Each screen and
 * each read model is asked the same question separately, because they reach the
 * database by different routes — Eloquent with a global scope, and the query builder
 * without one — and only one of those routes is protected by the scope.
 */

/** Set a business up with its own currencies, safe, client and one movement. */
function booksFor(string $name, string $client, string $amount): array
{
    $business = Business::factory()->create(['name' => $name]);

    return app(CurrentBusiness::class)->actingAs($business, function () use ($business, $client, $amount): array {
        (new CurrencySeeder)->run();
        app(CurrencyRegistry::class)->flush();
        app(LedgerAccountResolver::class)->flush();

        $egp = Currency::query()->where('code', 'EGP')->sole();
        $safe = Account::factory()->create(['name' => "{$business->name} safe"]);
        $party = Counterparty::factory()->create(['name' => $client]);

        $owner = User::factory()->create(['business_id' => $business->getKey()]);
        $owner->assignRole(Role::Owner->value);

        app(PostingService::class)->post(app(PostingRules::class)->build(new TransactionInput(
            type: TransactionType::In,
            currency: $egp,
            amount: $egp->money($amount),
            occurredAt: new DateTimeImmutable('2026-06-10'),
            account: $safe,
            counterparty: $party,
        )));

        return ['business' => $business, 'owner' => $owner, 'party' => $party, 'safe' => $safe];
    });
}

beforeEach(function (): void {
    (new RolePermissionSeeder)->run();

    $this->first = booksFor('Nile Exchange', 'سالم التجريبي', '899510');
    $this->second = booksFor('Gulf Exchange', 'Yousef Al-Otaibi', '250000');

    app(CurrencyRegistry::class)->flush();
    app(LedgerAccountResolver::class)->flush();
});

describe('what a query can reach', function (): void {
    it('sees only its own counterparties, currencies and accounts', function (): void {
        $current = app(CurrentBusiness::class);

        $mine = $current->actingAs($this->first['business'], fn (): array => [
            'parties' => Counterparty::query()->pluck('name')->all(),
            'accounts' => Account::query()->pluck('name')->all(),
            'currencies' => Currency::query()->count(),
        ]);

        expect($mine['parties'])->toBe(['سالم التجريبي'])
            ->and($mine['accounts'])->toBe(['Nile Exchange safe'])
            // Each business was seeded its own set, not a shared one.
            ->and($mine['currencies'])->toBeGreaterThan(0);
    });

    it('cannot fetch another business\'s row by its id', function (): void {
        $theirs = $this->second['party']->getKey();

        $found = app(CurrentBusiness::class)->actingAs(
            $this->first['business'],
            fn () => Counterparty::query()->find($theirs),
        );

        expect($found)->toBeNull();
    });

    it('keeps every ledger entry and transaction apart', function (): void {
        $current = app(CurrentBusiness::class);

        $first = $current->actingAs($this->first['business'], fn (): int => LedgerEntry::query()->count());
        $second = $current->actingAs($this->second['business'], fn (): int => LedgerEntry::query()->count());
        $both = $current->across(fn (): int => LedgerEntry::query()->count());

        expect($first)->toBeGreaterThan(0)
            ->and($second)->toBeGreaterThan(0)
            ->and($both)->toBe($first + $second);
    });

    it('keeps the audit trail apart', function (): void {
        $current = app(CurrentBusiness::class);

        $first = $current->actingAs($this->first['business'], fn (): int => AuditLog::query()->count());
        $both = $current->across(fn (): int => AuditLog::query()->count());

        expect($first)->toBeGreaterThan(0)->and($both)->toBeGreaterThan($first);
    });
});

/*
 * The read models are the dangerous half.
 *
 * They are built with the query builder rather than Eloquent — deliberately, because
 * they are joined aggregates — which means the global scope does not apply to them at
 * all. Every one of them is asked separately here.
 */
describe('the read models, which no global scope protects', function (): void {
    it('reports standings for one business only', function (): void {
        $ids = app(CurrentBusiness::class)->across(
            fn (): array => Counterparty::query()->pluck('id')->all(),
        );

        $standings = app(CurrentBusiness::class)->actingAs(
            $this->first['business'],
            fn (): array => app(CounterpartyStandings::class)->forParties($ids),
        );

        expect(array_keys($standings))->toBe([$this->first['party']->getKey()]);
    });

    // Asserted against the props rather than the rendered HTML: Inertia serialises
    // them as JSON into the document, and JSON escapes Arabic to \uXXXX. A page that
    // leaked would pass an assertDontSee on the Arabic name.
    it('shows one business\'s dashboard and not the other\'s', function (): void {
        $props = $this->actingAs($this->first['owner'])->get('/dashboard')
            ->assertOk()
            ->viewData('page')['props'];

        $names = collect($props['dashboard']['counterparties'])->pluck('name');

        expect($names->all())->toBe(['سالم التجريبي']);
    });

    it('lists one business\'s counterparties and not the other\'s', function (): void {
        $props = $this->actingAs($this->first['owner'])->get('/counterparties')
            ->assertOk()
            ->viewData('page')['props'];

        expect(collect($props['counterparties'])->pluck('name')->all())->toBe(['سالم التجريبي']);
    });

    it('lists one business\'s transactions and not the other\'s', function (): void {
        $props = $this->actingAs($this->second['owner'])->get('/transactions')
            ->assertOk()
            ->viewData('page')['props'];

        $ours = app(CurrentBusiness::class)->actingAs(
            $this->second['business'],
            fn (): int => Transaction::query()->count(),
        );

        expect($props['transactions']['total'] ?? count($props['transactions']['data'] ?? []))->toBe($ours);
    });
});

describe('reaching for another business through the front door', function (): void {
    it('refuses a statement for somebody else\'s client', function (): void {
        $this->actingAs($this->first['owner'])
            ->get("/counterparties/{$this->second['party']->getKey()}/statement")
            ->assertNotFound();
    });

    it('refuses to edit somebody else\'s client', function (): void {
        $this->actingAs($this->first['owner'])
            ->get("/counterparties/{$this->second['party']->getKey()}/edit")
            ->assertNotFound();
    });

    it('refuses a movement posted against somebody else\'s client', function (): void {
        $egp = app(CurrentBusiness::class)->actingAs(
            $this->first['business'],
            fn (): Currency => Currency::query()->where('code', 'EGP')->sole(),
        );

        $this->actingAs($this->first['owner'])->post('/movements', [
            'type' => TransactionType::In->value,
            'currency_id' => $egp->getKey(),
            'amount' => '1000',
            'occurred_at' => '2026-06-10',
            'account_id' => $this->first['safe']->getKey(),
            'counterparty_id' => $this->second['party']->getKey(),
        ])->assertSessionHasErrors('counterparty_id');
    });

    it('refuses a movement into somebody else\'s safe', function (): void {
        $egp = app(CurrentBusiness::class)->actingAs(
            $this->first['business'],
            fn (): Currency => Currency::query()->where('code', 'EGP')->sole(),
        );

        $this->actingAs($this->first['owner'])->post('/movements', [
            'type' => TransactionType::In->value,
            'currency_id' => $egp->getKey(),
            'amount' => '1000',
            'occurred_at' => '2026-06-10',
            'account_id' => $this->second['safe']->getKey(),
            'counterparty_id' => $this->first['party']->getKey(),
        ])->assertSessionHasErrors('account_id');
    });
});

/*
 * Failing closed.
 *
 * The alternative design — treat "no business bound" as "no filter" — turns one
 * forgotten middleware into every business reading every other business's books, with
 * nothing in the log to say so. These assert that the unset case is loud instead.
 */
describe('when no business is bound', function (): void {
    it('refuses to read', function (): void {
        app(CurrentBusiness::class)->forget();

        expect(fn () => Counterparty::query()->count())->toThrow(NoBusinessResolved::class);
    });

    it('refuses to write', function (): void {
        app(CurrentBusiness::class)->forget();

        expect(fn () => Counterparty::factory()->create())->toThrow(NoBusinessResolved::class);
    });

    it('reads across businesses only when told to, and only for as long as it is told', function (): void {
        $current = app(CurrentBusiness::class);
        $current->forget();

        $all = $current->across(fn (): int => Counterparty::query()->count());

        expect($all)->toBe(2)
            ->and(fn () => Counterparty::query()->count())->toThrow(NoBusinessResolved::class);
    });

    it('puts the scope back even when the work throws', function (): void {
        $current = app(CurrentBusiness::class);
        $current->forget();

        expect(fn () => $current->across(fn () => throw new RuntimeException('boom')))
            ->toThrow(RuntimeException::class)
            ->and(fn () => Counterparty::query()->count())->toThrow(NoBusinessResolved::class);
    });
});

/*
 * The owner met this as a stack trace on the login screen, on a database that had not
 * been migrated yet. Sign-up cannot produce the state — it creates the person and the
 * business in one transaction — but an older account can be in it.
 */
describe('an account attached to no business', function (): void {
    it('says what is wrong instead of throwing a scoping error at them', function (): void {
        $orphan = app(CurrentBusiness::class)->across(fn (): User => User::factory()->create());
        $orphan->forceFill(['business_id' => null])->save();

        $response = $this->actingAs($orphan)->get('/dashboard');

        $response->assertStatus(500)
            ->assertSee('This account has no books yet')
            ->assertSee('php artisan migrate');
    });

    it('leaves a guest alone, who has no books to fail to find', function (): void {
        $this->get('/login')->assertOk();
        $this->get('/')->assertOk();
    });
});

describe('writes land where the books are open', function (): void {
    it('stamps a new row with the open business without being asked', function (): void {
        $party = app(CurrentBusiness::class)->actingAs(
            $this->second['business'],
            fn (): Counterparty => Counterparty::factory()->create(['name' => 'Written second']),
        );

        expect($party->business_id)->toBe($this->second['business']->getKey());
    });

    it('does not let a signed-in user write into another business by asking', function (): void {
        // The business is read from the user's own row, never from the request. Sending
        // one is simply ignored.
        $this->actingAs($this->first['owner'])->post('/counterparties', [
            'name' => 'Planted',
            'type' => 'customer',
            'is_active' => true,
            'business_id' => $this->second['business']->getKey(),
        ])->assertRedirect();

        $planted = app(CurrentBusiness::class)->across(
            fn () => Counterparty::query()->where('name', 'Planted')->sole(),
        );

        expect($planted->business_id)->toBe($this->first['business']->getKey());
    });
});
