<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Currency;
use App\Models\User;

/**
 * Authorization for the currency list.
 *
 * Currencies define what every stored amount *means*. Changing a currency's precision
 * after balances exist reinterprets history, which is why managing them is a separate
 * permission from viewing them and is not granted to operators.
 */
final class CurrencyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewCurrencies->value);
    }

    public function view(User $user, Currency $currency): bool
    {
        return $user->can(Permission::ViewCurrencies->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageCurrencies->value);
    }

    public function update(User $user, Currency $currency): bool
    {
        return $user->can(Permission::ManageCurrencies->value);
    }

    /**
     * Never. Currencies are referenced by ledger history that must stay reproducible
     * (Section 7); they are deactivated instead. There is no route for this, and this
     * method exists so that any future attempt to add one fails closed.
     */
    public function delete(User $user, Currency $currency): bool
    {
        return false;
    }
}
