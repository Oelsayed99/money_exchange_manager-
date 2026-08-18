<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Reconciliation;
use App\Models\User;

final class ReconciliationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewReconciliations->value);
    }

    public function view(User $user, Reconciliation $reconciliation): bool
    {
        return $user->can(Permission::ViewReconciliations->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageReconciliations->value);
    }

    /**
     * Explaining a difference, and nothing else.
     *
     * The figures are frozen by a database trigger and by the model; this permits the
     * only change a reconciliation ever accepts.
     */
    public function resolve(User $user, Reconciliation $reconciliation): bool
    {
        return $user->can(Permission::ManageReconciliations->value);
    }

    /**
     * Never. A reconciliation is a record of what was found on a day; a mistaken count
     * is superseded by a new one, not erased.
     */
    public function delete(User $user, Reconciliation $reconciliation): bool
    {
        return false;
    }

    public function update(User $user, Reconciliation $reconciliation): bool
    {
        return false;
    }
}
