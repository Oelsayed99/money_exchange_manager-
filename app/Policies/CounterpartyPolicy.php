<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Counterparty;
use App\Models\User;

final class CounterpartyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewCounterparties->value);
    }

    public function view(User $user, Counterparty $counterparty): bool
    {
        return $user->can(Permission::ViewCounterparties->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageCounterparties->value);
    }

    public function update(User $user, Counterparty $counterparty): bool
    {
        return $user->can(Permission::ManageCounterparties->value);
    }

    /**
     * Never through the interface. A party is referenced by transaction history and by
     * the custody locations that belong to them; they are deactivated instead.
     */
    public function delete(User $user, Counterparty $counterparty): bool
    {
        return false;
    }
}
