<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One exchange office and everything it knows.
 *
 * The unit of isolation. Every currency, account, counterparty, entry, balance and
 * audit line belongs to exactly one of these, and no query in the application is
 * allowed to see across them without saying so out loud — see {@see BelongsToBusiness}.
 *
 * Deliberately *not* called an account. In this application an account is a safe, a
 * bank or a wallet that holds money, and has been since Phase 2. Two meanings for that
 * word in one codebase would be a bug waiting for whoever reads it next.
 *
 * @property int $id
 * @property string $name
 * @property int|null $owner_id
 * @property string $locale
 *
 * @method static BusinessFactory factory(...$parameters)
 */
final class Business extends Model
{
    use Auditable;

    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['name', 'owner_id', 'locale'];

    /**
     * The person who signed up.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
