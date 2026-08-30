<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Exceptions\NoBusinessResolved;
use App\Models\Business;
use Closure;

/**
 * Whose books are being read right now.
 *
 * Bound once per request from the signed-in user, and read by the global scope on every
 * model that belongs to a business. There is no default and no fallback: a query that
 * runs with nothing bound throws rather than quietly returning every business's rows.
 *
 * That is the whole design. The failure this exists to prevent — one exchange office
 * seeing another's balances — is silent by nature, and the only reliable defence
 * against a silent failure is to make the unset case loud.
 *
 * Reading across businesses is legitimate in exactly two places: a console command that
 * sweeps every set of books, and the sign-up that creates the first one. Both say so
 * with {@see across()}, which is greppable, rather than by leaving the scope unset.
 */
final class CurrentBusiness
{
    private ?Business $business = null;

    /** Depth rather than a flag, so nested {@see across()} calls cannot unset each other. */
    private int $unscoped = 0;

    public function set(Business $business): void
    {
        $this->business = $business;
    }

    public function forget(): void
    {
        $this->business = null;
    }

    public function has(): bool
    {
        return $this->business instanceof Business;
    }

    /** @throws NoBusinessResolved when nothing is bound */
    public function get(): Business
    {
        return $this->business ?? throw NoBusinessResolved::forRead();
    }

    /** @throws NoBusinessResolved when nothing is bound */
    public function id(): int
    {
        return $this->get()->getKey();
    }

    /** Whether reads are currently allowed to cross businesses. */
    public function isUnscoped(): bool
    {
        return $this->unscoped > 0;
    }

    /**
     * Run something across every business, deliberately.
     *
     * For `ledger:verify` sweeping all books, for sign-up creating the first one, and
     * for nothing else. Restores the previous state even if the callback throws.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     */
    public function across(Closure $work): mixed
    {
        $this->unscoped++;

        try {
            return $work();
        } finally {
            $this->unscoped--;
        }
    }

    /**
     * Run something as one particular business.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     */
    public function actingAs(Business $business, Closure $work): mixed
    {
        $previous = $this->business;
        $this->business = $business;

        try {
            return $work();
        } finally {
            $this->business = $previous;
        }
    }
}
