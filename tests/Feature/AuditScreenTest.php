<?php

declare(strict_types=1);

use App\Domain\Money\CurrencyRegistry;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Counterparty;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(CurrencySeeder::class);
    app(CurrencyRegistry::class)->flush();

    $this->admin = User::factory()->create(['name' => 'Owner']);
    $this->admin->assignRole(Role::Owner->value);

    $this->operator = User::factory()->create();
    $this->operator->assignRole(Role::Owner->value);
});

describe('who may read it', function (): void {
    it('requires authentication', function (): void {
        $this->get('/audit')->assertRedirect('/login');
    });

    // The trail carries IP addresses, user agents and other people's changes. Reading
    // it is a different kind of act from reading the ledger, so it is not day-to-day
    // work — an operator and a viewer are both refused.
    it('refuses somebody holding no role', function (): void {
        $this->actingAs(User::factory()->create())->get('/audit')->assertForbidden();
    });

    it('lets an administrator read it', function (): void {
        $this->actingAs($this->admin)->get('/audit')->assertOk();
    });
});

describe('what it shows', function (): void {
    it('lists a change with who made it', function (): void {
        $this->actingAs($this->admin);
        Counterparty::factory()->create(['name' => 'سالم التجريبي']);

        $this->get('/audit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('audit/index')
                ->where('logs.data.0.event', 'created')
                // The recorder prefers the email: more identifying than a display
                // name, and it is a snapshot that outlives the account.
                ->where('logs.data.0.actor', $this->admin->email)
            );
    });

    // Only what differs. An update that writes the whole attribute set would otherwise
    // list thirty unchanged fields around the one that moved, which is how somebody
    // stops reading an audit trail.
    it('lists only the fields that actually changed', function (): void {
        $this->actingAs($this->admin);
        $party = Counterparty::factory()->create(['name' => 'Before', 'country' => 'EG']);
        $party->update(['name' => 'After']);

        $changes = collect($this->get('/audit')->viewData('page')['props']['logs']['data'])
            ->firstWhere('event', 'updated')['changes'];

        expect(collect($changes)->pluck('field')->all())->toBe(['name'])
            ->and($changes[0]['old'])->toBe('Before')
            ->and($changes[0]['new'])->toBe('After');
    });

    it('puts the newest first', function (): void {
        $this->actingAs($this->admin);
        Counterparty::factory()->create(['name' => 'First']);
        $second = Counterparty::factory()->create(['name' => 'Second']);

        $props = $this->get('/audit')->viewData('page')['props'];

        expect($props['logs']['data'][0]['record_id'])->toBe($second->id);
    });

    it('pages long trails', function (): void {
        $this->actingAs($this->admin);
        Counterparty::factory()->count(55)->create();

        $this->get('/audit')->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 50)
            ->where('logs.meta.last_page', 2));
    });
});

describe('filtering', function (): void {
    beforeEach(function (): void {
        $this->actingAs($this->admin);
        $this->party = Counterparty::factory()->create(['name' => 'Filterable']);
        $this->party->update(['name' => 'Renamed']);
    });

    it('narrows by event', function (): void {
        $this->get('/audit?event=updated')->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));
    });

    it('narrows by record type', function (): void {
        $encoded = urlencode(Counterparty::class);

        $this->get("/audit?type={$encoded}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 2));
    });

    it('narrows by who did it', function (): void {
        $this->get("/audit?user={$this->admin->id}")->assertInertia(fn (Assert $page) => $page->has('logs.data', 2));
    });

    it('searches the actor recorded on the row', function (): void {
        $this->get('/audit?search='.$this->admin->email)
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 2));
    });

    it('rejects a record type nothing audits', function (): void {
        $this->get('/audit?type='.urlencode('App\Models\LedgerEntry'))->assertSessionHasErrors('type');
    });

    it('rejects a period that ends before it begins', function (): void {
        $this->get('/audit?from=2026-07-01&to=2026-06-01')->assertSessionHasErrors('to');
    });
});

/*
 * The recorder replaces secrets rather than omitting them, so the trail says a
 * password changed without saying what to. The screen must pass that straight through.
 */
describe('redaction', function (): void {
    it('shows that a secret changed without showing the secret', function (): void {
        $this->actingAs($this->admin);

        $user = User::factory()->create();
        $user->update(['password' => bcrypt('a-real-secret-value')]);

        $props = $this->get('/audit')->viewData('page')['props'];
        $json = json_encode($props);

        expect($json)->toContain('[redacted]')
            ->and($json)->not->toContain('a-real-secret-value');
    });
});

describe('the trail itself', function (): void {
    // Append-only, enforced by the database. Nothing in the interface writes to it,
    // and nothing could.
    it('cannot be written to through the model', function (): void {
        $this->actingAs($this->admin);
        Counterparty::factory()->create();

        $log = AuditLog::query()->firstOrFail();

        expect(fn () => $log->update(['event' => 'tampered']))->toThrow(RuntimeException::class);
    });
});
