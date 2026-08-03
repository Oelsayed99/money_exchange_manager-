<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Currency;

/** @param array<string, mixed> $overrides */
function validCurrency(array $overrides = []): array
{
    return [
        'code' => 'KWD',
        'name' => 'Kuwaiti Dinar',
        'name_ar' => null,
        'symbol' => 'د.ك',
        'decimal_places' => 3,
        'is_active' => true,
        'sort_order' => 0,
        ...$overrides,
    ];
}

describe('role definitions', function (): void {
    it('grants an administrator every permission', function (): void {
        $user = userWithRole(Role::Administrator);

        foreach (Permission::cases() as $permission) {
            expect($user->can($permission->value))->toBeTrue();
        }
    });

    // Currencies define what every stored amount means. An operator reads them and
    // cannot change them.
    it('lets an operator read currencies but not change them', function (): void {
        $user = userWithRole(Role::Operator);

        expect($user->can(Permission::ViewCurrencies->value))->toBeTrue()
            ->and($user->can(Permission::ManageCurrencies->value))->toBeFalse();
    });

    it('gives a viewer read access only', function (): void {
        $user = userWithRole(Role::Viewer);

        expect($user->can(Permission::ViewCurrencies->value))->toBeTrue()
            ->and($user->can(Permission::ManageCurrencies->value))->toBeFalse();
    });

    it('gives a user with no role nothing at all', function (): void {
        $user = userWithoutRole();

        foreach (Permission::cases() as $permission) {
            expect($user->can($permission->value))->toBeFalse();
        }
    });

    // Administrator is granted every permission explicitly rather than through a
    // Gate::before bypass, so the permission table is the answer to "what can an
    // administrator do" — and re-seeding picks up newly added permissions.
    it('keeps the administrator in step with the Permission enum', function (): void {
        $user = userWithRole(Role::Administrator);

        expect($user->getAllPermissions()->pluck('name')->sort()->values()->all())
            ->toBe(collect(Permission::values())->sort()->values()->all());
    });
});

describe('reading currencies', function (): void {
    it('allows a viewer to see the list', function (): void {
        $this->actingAs(userWithRole(Role::Viewer))->get('/currencies')->assertOk();
    });

    it('forbids a user without permission', function (): void {
        $this->actingAs(userWithoutRole())->get('/currencies')->assertForbidden();
    });
});

describe('managing currencies', function (): void {
    it('forbids a viewer from opening the create form', function (): void {
        $this->actingAs(userWithRole(Role::Viewer))->get('/currencies/create')->assertForbidden();
    });

    it('forbids an operator from opening the create form', function (): void {
        $this->actingAs(userWithRole(Role::Operator))->get('/currencies/create')->assertForbidden();
    });

    // The important case: not merely a hidden button, but a rejected request.
    it('forbids a viewer from creating a currency directly', function (): void {
        $this->actingAs(userWithRole(Role::Viewer))
            ->post('/currencies', validCurrency())
            ->assertForbidden();

        expect(Currency::query()->count())->toBe(0);
    });

    it('forbids an operator from creating a currency directly', function (): void {
        $this->actingAs(userWithRole(Role::Operator))
            ->post('/currencies', validCurrency())
            ->assertForbidden();

        expect(Currency::query()->count())->toBe(0);
    });

    it('forbids a viewer from editing a currency', function (): void {
        $currency = Currency::factory()->create(['code' => 'AED', 'decimal_places' => 2]);

        $this->actingAs(userWithRole(Role::Viewer))
            ->get("/currencies/{$currency->id}/edit")
            ->assertForbidden();
    });

    it('forbids a viewer from updating a currency directly', function (): void {
        $currency = Currency::factory()->create(['code' => 'AED', 'decimal_places' => 2]);

        $this->actingAs(userWithRole(Role::Viewer))
            ->put("/currencies/{$currency->id}", validCurrency(['code' => 'AED', 'decimal_places' => 8]))
            ->assertForbidden();

        expect($currency->fresh()?->decimal_places)->toBe(2);
    });

    it('allows an administrator to create and update', function (): void {
        $admin = userWithRole(Role::Administrator);

        $this->actingAs($admin)->post('/currencies', validCurrency())->assertRedirect('/currencies');

        $currency = Currency::query()->where('code', 'KWD')->firstOrFail();

        $this->actingAs($admin)
            ->put("/currencies/{$currency->id}", validCurrency(['name' => 'Renamed']))
            ->assertRedirect('/currencies');

        expect($currency->fresh()?->name)->toBe('Renamed');
    });

    // Authorization runs in the form request, before validation, so an unauthorized
    // caller learns nothing about what the form expects.
    it('refuses an unauthorized request before validating it', function (): void {
        $this->actingAs(userWithRole(Role::Viewer))
            ->post('/currencies', [])
            ->assertForbidden()
            ->assertSessionHasNoErrors();
    });
});

describe('shared permissions', function (): void {
    it('sends only the permissions the user holds', function (): void {
        $props = $this->actingAs(userWithRole(Role::Operator))
            ->get('/dashboard')
            ->viewData('page')['props'];

        expect($props['auth']['permissions'])->toBe([Permission::ViewCurrencies->value]);
    });

    it('sends an administrator every permission', function (): void {
        $props = $this->actingAs(userWithRole(Role::Administrator))
            ->get('/dashboard')
            ->viewData('page')['props'];

        expect($props['auth']['permissions'])->toBe(Permission::values());
    });

    // A user must not be able to enumerate the permissions they are missing.
    it('never lists a permission the user lacks', function (): void {
        $props = $this->actingAs(userWithoutRole())
            ->get('/dashboard')
            ->viewData('page')['props'];

        expect($props['auth']['permissions'])->toBe([]);
    });
});

describe('deletion', function (): void {
    // Currencies are referenced by ledger history that must stay reproducible
    // (Section 7). The policy denies deletion for everyone, including administrators,
    // so that adding a route later fails closed rather than open.
    it('denies deletion to everyone, administrators included', function (): void {
        $currency = Currency::factory()->create();

        expect(userWithRole(Role::Administrator)->can('delete', $currency))->toBeFalse()
            ->and(userWithRole(Role::Operator)->can('delete', $currency))->toBeFalse();
    });
});
