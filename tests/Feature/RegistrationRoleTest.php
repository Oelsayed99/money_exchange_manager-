<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;

/** @param array<string, string> $overrides */
function registration(array $overrides = []): array
{
    return [
        'name' => 'Someone',
        'email' => 'someone@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$overrides,
    ];
}

// Bootstrap: the very first account must be able to administer the system, because
// there is nobody yet to grant it anything.
it('makes the first registered user an administrator', function (): void {
    $this->post('/register', registration())->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'someone@example.com')->firstOrFail();

    expect($user->hasRole(Role::Administrator->value))->toBeTrue()
        ->and($user->can(Permission::ManageCurrencies->value))->toBeTrue();
});

// Everyone after the first starts read-only. A self-service route to managing the
// currencies the ledger depends on would not be a permission system at all.
it('makes every later registration a viewer', function (): void {
    $this->post('/register', registration());
    $this->post('/logout');

    $this->post('/register', registration(['email' => 'second@example.com']));

    $second = User::query()->where('email', 'second@example.com')->firstOrFail();

    expect($second->hasRole(Role::Viewer->value))->toBeTrue()
        ->and($second->can(Permission::ViewCurrencies->value))->toBeTrue()
        ->and($second->can(Permission::ManageCurrencies->value))->toBeFalse();
});

// A fresh installation may not have been seeded. Registration must still work, and the
// first account must not end up holding a role that grants nothing.
it('seeds the role matrix when registering against an unseeded database', function (): void {
    expect(Spatie\Permission\Models\Role::query()->count())->toBe(0);

    $this->post('/register', registration());

    $user = User::query()->where('email', 'someone@example.com')->firstOrFail();

    expect($user->hasRole(Role::Administrator->value))->toBeTrue()
        ->and($user->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::values())->sort()->values()->all());
});

it('assigns exactly one role, replacing rather than accumulating', function (): void {
    $this->post('/register', registration());

    $user = User::query()->where('email', 'someone@example.com')->firstOrFail();
    $user->syncRoles([Role::Viewer->value]);

    expect($user->fresh()?->getRoleNames()->all())->toBe([Role::Viewer->value]);
});
