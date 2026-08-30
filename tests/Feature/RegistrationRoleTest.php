<?php

declare(strict_types=1);

use App\Domain\Tenancy\CurrentBusiness;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Business;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;

/**
 * Signing up creates a business, and the person who signed up owns it.
 *
 * This used to make the first account an administrator and every account after it a
 * viewer, which was right while the application was one office's books. Once books are
 * kept per business that rule is not merely obsolete, it is a bug: it made the second
 * person to sign up a read-only spectator of the first person's ledger.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function registration(array $overrides = []): array
{
    return [
        'name' => 'Someone',
        'business_name' => 'Nile Exchange',
        'email' => 'someone@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$overrides,
    ];
}

function registered(string $email = 'someone@example.com'): User
{
    return app(CurrentBusiness::class)->across(
        fn (): User => User::query()->where('email', $email)->firstOrFail(),
    );
}

it('gives the person who signs up their own business, and every permission in it', function (): void {
    $this->post('/register', registration())->assertRedirect(route('dashboard', absolute: false));

    $user = registered();

    expect($user->hasRole(Role::Owner->value))->toBeTrue()
        ->and($user->can(Permission::ManageCurrencies->value))->toBeTrue()
        ->and($user->business?->name)->toBe('Nile Exchange')
        ->and($user->business?->owner_id)->toBe($user->getKey());
});

// The bug the old rule became. Two sign-ups are two businesses, and the second is as
// much an owner of theirs as the first is of theirs.
it('makes the second sign-up an owner too, of a different business', function (): void {
    $this->post('/register', registration());
    $this->post('/logout');

    $this->post('/register', registration([
        'business_name' => 'Gulf Exchange',
        'email' => 'second@example.com',
    ]));

    $first = registered();
    $second = registered('second@example.com');

    expect($second->hasRole(Role::Owner->value))->toBeTrue()
        ->and($second->can(Permission::ManageCurrencies->value))->toBeTrue()
        ->and($second->business_id)->not->toBe($first->business_id);
});

// Empty books are what a new set of books is — but not so empty that nothing can be
// recorded. A currency and somewhere to put money are the minimum for the first
// movement to be possible at all.
it('starts a new business with currencies and one safe, and nothing invented', function (): void {
    $this->post('/register', registration());

    $business = registered()->business;

    $contents = app(CurrentBusiness::class)->actingAs($business, fn (): array => [
        'currencies' => Currency::query()->count(),
        'accounts' => Account::query()->pluck('name')->all(),
        'parties' => Counterparty::query()->count(),
        'transactions' => Transaction::query()->count(),
    ]);

    expect($contents['currencies'])->toBeGreaterThan(0)
        ->and($contents['accounts'])->toBe(['Main safe'])
        ->and($contents['parties'])->toBe(0)
        ->and($contents['transactions'])->toBe(0);
});

// A fresh installation may never have been seeded. Registering must still work, and the
// owner must not end up holding a role that grants nothing.
it('seeds the role matrix when registering against an unseeded database', function (): void {
    Spatie\Permission\Models\Role::query()->delete();

    $this->post('/register', registration());

    expect(registered()->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::values())->sort()->values()->all());
});

it('will not create a business without a name for it', function (): void {
    $this->post('/register', registration(['business_name' => '']))
        ->assertSessionHasErrors('business_name');

    expect(app(CurrentBusiness::class)->across(fn (): int => Business::query()->count()))
        // Only the one every test starts inside.
        ->toBe(1);
});

// A half-provisioned sign-up is worse than a failed one: the global scope refuses every
// query for a user with no business, so they could not load a single screen and would
// have no way to tell you why.
it('creates the business and the user together, or neither', function (): void {
    $before = app(CurrentBusiness::class)->across(fn (): int => Business::query()->count());

    $this->post('/register', registration(['email' => 'not-an-address']))
        ->assertSessionHasErrors('email');

    expect(app(CurrentBusiness::class)->across(fn (): int => Business::query()->count()))->toBe($before)
        ->and(app(CurrentBusiness::class)->across(fn (): int => User::query()->count()))->toBe(0);
});
