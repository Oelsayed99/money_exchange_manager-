<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles recognised by the application.
 *
 * One, now. The system used to be a single office's books worked by a few named people,
 * so it distinguished an administrator from an operator from a viewer. Online it is one
 * set of books per sign-up, and the person who signed up owns theirs outright — there
 * is nobody else in them to be told apart from.
 *
 * The permission plumbing stays. Each ability is still granted explicitly and checked
 * at the gate, so an office that later wants a clerk who can record but not reverse can
 * have one by adding a case here; nothing above this file has to change for that.
 *
 * What is gone is the old bootstrap rule, under which the first account became an
 * administrator and every account after it a viewer. Once books are per business that
 * rule is not merely obsolete, it is wrong: it made the second person to sign up a
 * read-only spectator of the first person's business.
 */
enum Role: string
{
    /** The person who signed up. Everything, within their own books and nowhere else. */
    case Owner = 'owner';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }

    /**
     * Permissions granted to this role.
     *
     * Granted explicitly rather than through a `Gate::before` bypass, so "what can an
     * owner do" is answerable by reading the permission table rather than by reading
     * code. The seeder re-syncs on every run, so a newly added permission is picked up.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
        };
    }
}
