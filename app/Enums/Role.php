<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles recognised by the application.
 *
 * Deliberately few. This is an internal system with a small number of people, and a
 * role matrix nobody can hold in their head is a matrix nobody audits.
 */
enum Role: string
{
    /** Full access, including reference data that the ledger depends on. */
    case Administrator = 'administrator';

    /** Day-to-day work. Reads reference data; cannot change what money means. */
    case Operator = 'operator';

    /** Read-only. */
    case Viewer = 'viewer';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }

    /**
     * Permissions granted to this role.
     *
     * Administrator is intentionally *not* special-cased through a Gate::before
     * bypass. It is granted every permission explicitly, so "what can an administrator
     * do" is answerable by reading the permission table rather than by reading code.
     * The seeder re-syncs on every run, so a newly added permission is picked up.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Administrator => Permission::cases(),
            self::Operator => [Permission::ViewCurrencies],
            self::Viewer => [Permission::ViewCurrencies],
        };
    }
}
