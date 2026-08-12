<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One recorded change to one record.
 *
 * Read-only from the application's point of view. The database rejects updates and
 * deletes outright; this model refuses to attempt them so the failure is a clear
 * exception at the call site rather than a SQL error from a trigger.
 *
 * @property int $id
 * @property string $auditable_type
 * @property int $auditable_id
 * @property string $event
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property int|null $user_id
 * @property string|null $actor_label
 * @property string $source
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
final class AuditLog extends Model
{
    /** Rows carry a creation time and nothing else; they are never modified. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'event',
        'old_values',
        'new_values',
        'user_id',
        'actor_label',
        'source',
        'ip_address',
        'user_agent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('Audit log entries are append-only and cannot be updated.');
        });

        self::deleting(function (): never {
            throw new RuntimeException('Audit log entries are append-only and cannot be deleted.');
        });
    }
}
