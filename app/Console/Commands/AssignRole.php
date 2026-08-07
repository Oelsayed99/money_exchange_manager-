<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grant a role from the command line.
 *
 * A stopgap until there is a user administration screen. Roles have to be grantable by
 * someone, and the alternative — letting users pick their own — would make the
 * permission system decorative.
 */
final class AssignRole extends Command
{
    protected $signature = 'user:role {email : The user\'s email address} {role : One of administrator, operator, viewer}';

    protected $description = 'Assign a role to a user, replacing any role they already hold';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $roleName = (string) $this->argument('role');

        $role = Role::tryFrom($roleName);

        if ($role === null) {
            $this->error("Unknown role [{$roleName}]. Available: ".implode(', ', Role::values()));

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email [{$email}].");

            return self::FAILURE;
        }

        // syncRoles rather than assignRole: a user holds exactly one role, so granting
        // a new one must replace the old rather than accumulate permissions silently.
        $user->syncRoles([$role->value]);

        $this->info("{$email} is now {$role->value}.");

        return self::SUCCESS;
    }
}
