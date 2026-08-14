<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\User;

final class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewAccounts->value);
    }

    public function view(User $user, Account $account): bool
    {
        return $user->can(Permission::ViewAccounts->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageAccounts->value);
    }

    public function update(User $user, Account $account): bool
    {
        return $user->can(Permission::ManageAccounts->value);
    }

    /**
     * Never through the interface. An account is referenced by ledger history that
     * must stay reproducible (Section 7); it is deactivated instead.
     */
    public function delete(User $user, Account $account): bool
    {
        return false;
    }
}
