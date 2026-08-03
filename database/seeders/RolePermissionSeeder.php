<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Synchronise roles and permissions with the enums.
 *
 * Idempotent and re-runnable. Adding a case to Permission and re-seeding is the
 * intended workflow: administrators pick up the new permission automatically, and the
 * other roles gain it only if Role::permissions() says so.
 *
 * Permissions are never deleted here. Removing one from the enum leaves the row in
 * place rather than silently revoking access as a side effect of a code change —
 * revocation should be a deliberate, visible act.
 */
final class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (RoleEnum::cases() as $roleEnum) {
            $role = Role::findOrCreate($roleEnum->value, 'web');

            $role->syncPermissions(
                array_map(fn (PermissionEnum $permission): string => $permission->value, $roleEnum->permissions())
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
