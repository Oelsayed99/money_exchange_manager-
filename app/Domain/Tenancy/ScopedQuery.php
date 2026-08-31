<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Reporting\CounterpartyStandings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A query builder that cannot forget whose books it is reading.
 *
 * The read models are written with the query builder rather than Eloquent, on purpose:
 * they are joined aggregates, and an Eloquent row would apply one model's casts to
 * another model's columns (see {@see CounterpartyStandings}).
 * The cost is that a global scope cannot reach them — `DB::table('ledger_entries')`
 * returns every business's entries and looks entirely ordinary doing it.
 *
 * So the read models go through here instead, and a structural test asserts that no
 * `DB::table(` survives anywhere in `app/Domain`. The filter is applied at the point
 * the table is named, before a caller has the chance to build on it.
 */
final readonly class ScopedQuery
{
    public function __construct(private CurrentBusiness $current) {}

    /**
     * One table, already narrowed to the business whose books are open.
     *
     * @param  string|null  $alias  the short name a join uses, as in `reconciliations as r`
     */
    public function table(string $table, ?string $alias = null): Builder
    {
        $qualifier = $alias ?? $table;

        return DB::table($alias === null ? $table : "{$table} as {$alias}")
            ->where("{$qualifier}.business_id", $this->current->id());
    }

    /**
     * Narrow a table that is being joined to one already narrowed.
     *
     * Redundant by construction — a ledger entry's account belongs to the same business
     * the entry does — and applied anyway. The redundancy is the point: it means a join
     * condition written wrongly cannot widen the result past this business.
     */
    public function join(Builder $query, string $qualifier): Builder
    {
        return $query->where("{$qualifier}.business_id", $this->current->id());
    }
}
