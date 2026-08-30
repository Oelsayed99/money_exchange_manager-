<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Business;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Turns a sign-up into a working set of books.
 *
 * Everything here happens in one transaction, because a half-provisioned business is
 * worse than a failed sign-up: a user with no business cannot load a single screen —
 * the global scope refuses every query — and they have no way to tell you why.
 *
 * What a new business starts with is deliberately small. Currencies, because the
 * application cannot record anything without at least one and choosing four sensible
 * ones is kinder than an empty screen. One safe, for the same reason. Nothing else is
 * invented: no counterparties, no opening positions, no sample movements. The books are
 * empty, which is what a new set of books is.
 */
final readonly class BusinessProvisioner
{
    public function __construct(private CurrentBusiness $current) {}

    /**
     * Create a business and the person who owns it.
     *
     * @param  array<string, mixed>  $attributes  passed to the user, less name and email
     */
    public function provision(string $businessName, string $name, string $email, array $attributes = []): User
    {
        return DB::transaction(function () use ($businessName, $name, $email, $attributes): User {
            // A fresh installation may never have been seeded. Registering must not fail
            // with "role does not exist", and the owner must not end up holding a role
            // that grants nothing. Idempotent, so it is a no-op from the second sign-up.
            if (SpatieRole::query()->count() === 0) {
                (new RolePermissionSeeder)->run();
            }

            // Created outside anybody's books — there are none yet — which is the case
            // across() exists for.
            $business = $this->current->across(
                fn (): Business => Business::query()->create(['name' => $businessName, 'locale' => app()->getLocale()]),
            );

            // forceFill rather than create(...$attributes): some of what callers pass
            // here — email_verified_at in particular — is deliberately *not* fillable,
            // because a sign-up form must never be able to set it. Mass assignment
            // drops those silently, which is how a Google sign-up ended up unverified.
            $user = new User;

            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'business_id' => $business->getKey(),
                ...$attributes,
            ])->save();

            $business->update(['owner_id' => $user->getKey()]);

            $user->assignRole(Role::Owner->value);

            // From here on, everything created belongs to the new business.
            $this->current->actingAs($business, function (): void {
                (new CurrencySeeder)->run();

                Account::query()->create([
                    'name' => __('accounts.first_safe'),
                    'type' => AccountType::Safe,
                    'is_active' => true,
                    'sort_order' => 1,
                ]);
            });

            return $user;
        });
    }
}
