<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Domain\Tenancy\CurrentBusiness;
use App\Models\Business;
use Closure;
use Illuminate\Support\Collection;

/**
 * Lets a console command work through every set of books, one at a time.
 *
 * A command has no signed-in user, so nothing binds a business for it — and the global
 * scope refuses any query without one. Rather than let commands read across businesses
 * and hope their `where` clauses are right, each one opens a single business's books,
 * does its work, and moves on. That way a command sees exactly what the application
 * sees, which is what makes its answer worth anything.
 *
 * `--business=` narrows it to one, by id or by name.
 */
trait RunsPerBusiness
{
    /**
     * Run the callback once per business, with that business's books open.
     *
     * @param  Closure(Business): int  $work  returns a count of problems, or 0
     * @return int the total across every business
     */
    protected function forEachBusiness(Closure $work): int
    {
        $businesses = $this->businessesToVisit();

        if ($businesses->isEmpty()) {
            $this->warn('No businesses to work through.');

            return 0;
        }

        $current = app(CurrentBusiness::class);
        $total = 0;

        foreach ($businesses as $business) {
            // Only announced when there is more than one, so a single-business install
            // reads exactly as it did before any of this existed.
            if ($businesses->count() > 1) {
                $this->line("<comment>{$business->name}</comment> (#{$business->getKey()})");
            }

            $total += $current->actingAs($business, fn (): int => $work($business));
        }

        return $total;
    }

    /** @return Collection<int, Business> */
    private function businessesToVisit(): Collection
    {
        $query = Business::query()->orderBy('id');

        $only = $this->option('business');

        if (is_string($only) && $only !== '') {
            $query->where(fn ($q) => $q->where('id', $only)->orWhere('name', $only));
        }

        return $query->get();
    }
}
