<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * A query touched one business's books with no business bound.
 *
 * Thrown rather than defaulted. The alternative — treating "nothing bound" as "no
 * filter" — turns a missing middleware into every business reading every other
 * business's balances, and does it without a single error in the log.
 */
final class NoBusinessResolved extends RuntimeException
{
    public static function forRead(): self
    {
        return new self(
            'No business is bound for this query. A model scoped to a business cannot be read '
            .'or written without one. In a request this comes from the signed-in user; in a '
            .'command or a test, bind it with CurrentBusiness::set() or actingAs(), or say '
            .'explicitly that the work crosses businesses with CurrentBusiness::across().'
        );
    }
}
