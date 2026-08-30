<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * "This id exists" — in *our* books, not in the table.
 *
 * `Rule::exists('counterparties', 'id')` builds its own query straight against the
 * table. No model, no global scope: it will happily confirm that another business's
 * client exists, and a form that only validates existence then accepts it.
 *
 * That is not theoretical. Before this, a signed-in operator could post a movement
 * against another business's counterparty and into another business's safe by sending
 * two ids — the request validated, and the entries landed. See
 * tests/Feature/BusinessIsolationTest.php.
 *
 * Every `exists` rule touching a table that belongs to a business goes through here,
 * and a structural test asserts that no bare `Rule::exists` is left in the application.
 */
final class Owned
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)
            ->where('business_id', app(CurrentBusiness::class)->id());
    }
}
