<?php

declare(strict_types=1);

use App\Enums\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The three old roles become the one that is left.
 *
 * Administrator, operator and viewer told apart several people working one office's
 * books. Online it is one set of books per sign-up and the person who signed up owns
 * theirs outright, so the enum has a single case and the seeder creates a single role.
 *
 * Without this, an account that existed before still holds `administrator` — a row the
 * seeder no longer maintains and the application no longer names. It keeps working,
 * which is the problem: it works by accident, and drifts further every time a
 * permission is added.
 *
 * The retired roles are deleted rather than left empty. A role nothing grants and
 * nothing assigns is a trap for whoever reads the table next.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array RETIRED = ['administrator', 'operator', 'viewer'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Makes sure `owner` exists and holds every permission before anybody is moved
        // onto it. Idempotent, so this is a no-op where it has already run.
        (new RolePermissionSeeder)->run();

        $owner = DB::table('roles')->where('name', Role::Owner->value)->value('id');
        $retired = DB::table('roles')->whereIn('name', self::RETIRED)->pluck('id');

        if ($owner === null || $retired->isEmpty()) {
            return;
        }

        // Everyone holding a retired role becomes an owner of their own books. Written
        // as one insert-ignore rather than a rename, because somebody could hold two of
        // them and would otherwise end up assigned twice.
        $holders = DB::table('model_has_roles')
            ->whereIn('role_id', $retired)
            ->get(['model_type', 'model_id'])
            ->unique(fn (object $row): string => $row->model_type.':'.$row->model_id);

        foreach ($holders as $holder) {
            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $owner,
                'model_type' => $holder->model_type,
                'model_id' => $holder->model_id,
            ]);
        }

        DB::table('model_has_roles')->whereIn('role_id', $retired)->delete();
        DB::table('role_has_permissions')->whereIn('role_id', $retired)->delete();
        DB::table('roles')->whereIn('id', $retired)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The three roles can be recreated, but who held which cannot: everybody is an
        // owner now, and there is nothing recorded to say who used to be a viewer.
        // Recreating them empty would be worse than leaving them out.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
