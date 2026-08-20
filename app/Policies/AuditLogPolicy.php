<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\User;

/**
 * The audit trail is read, and only read.
 *
 * Append-only at the database, which enforces it with triggers. Nothing here permits
 * a write, because there is no write to permit.
 */
final class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewAudit->value);
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $user->can(Permission::ViewAudit->value);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $log): bool
    {
        return false;
    }
}
