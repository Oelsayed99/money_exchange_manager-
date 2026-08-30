<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Tenancy\CurrentBusiness;
use App\Models\AuditLog;
use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;

/**
 * Confines a model to the business whose books are open.
 *
 * Two halves, and both are needed. Reads get a global scope, so no query anywhere can
 * forget the filter. Writes get a creating hook, so no insert anywhere can forget the
 * column — an unscoped write is the worse of the two, because it lands in another
 * business's books and stays there.
 *
 * The scope filters on the model's own qualified column. Unqualified, a statement
 * joining transactions to ledger entries — both scoped — would fail on an ambiguous
 * `business_id`.
 */
trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new class implements Scope
        {
            public function apply(Builder $builder, Model $model): void
            {
                $current = app(CurrentBusiness::class);

                if ($current->isUnscoped()) {
                    return;
                }

                // Throws when nothing is bound. See NoBusinessResolved for why that is
                // better than returning everything.
                $builder->where($model->qualifyColumn('business_id'), $current->id());
            }
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('business_id') !== null) {
                return;
            }

            $current = app(CurrentBusiness::class);

            if (! $current->has() && static::businessMayBeAbsent()) {
                return;
            }

            // Throws when nothing is bound and this model requires one, which is all
            // of them but the audit trail.
            $model->setAttribute('business_id', $current->id());
        });
    }

    /**
     * Whether a row of this kind may exist outside any business.
     *
     * False everywhere but the audit trail. Creating a business is a platform event
     * rather than something that happened inside somebody's books, and there is no
     * business to attribute it to at the moment it is written — the row being audited
     * is the business itself. See {@see AuditLog}.
     */
    protected static function businessMayBeAbsent(): bool
    {
        return false;
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
