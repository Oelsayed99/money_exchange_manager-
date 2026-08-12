<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Records every create, update and delete of a model to the audit trail.
 *
 * Applied per model rather than globally: auditing everything indiscriminately buries
 * the entries that matter under session and cache churn, and Section 15 asks for a
 * trail somebody can actually read.
 *
 * @phpstan-require-extends Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (self $model): void {
            app(AuditRecorder::class)->record(
                $model,
                AuditEvent::Created,
                null,
                $model->auditableAttributes($model->getAttributes()),
            );
        });

        static::updated(function (self $model): void {
            $changed = $model->auditableAttributes($model->getChanges());

            // A save that altered nothing but a timestamp is not a change anyone needs
            // to read about.
            if ($changed === []) {
                return;
            }

            $original = array_intersect_key($model->getRawOriginal(), $changed);

            app(AuditRecorder::class)->record(
                $model,
                AuditEvent::Updated,
                $model->auditableAttributes($original),
                $changed,
            );
        });

        static::deleted(function (self $model): void {
            app(AuditRecorder::class)->record(
                $model,
                AuditEvent::Deleted,
                $model->auditableAttributes($model->getRawOriginal()),
                null,
            );
        });
    }

    /** @return MorphMany<AuditLog, $this> */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')->latest('id');
    }

    /**
     * Strip noise and mask secrets before an attribute set reaches the trail.
     *
     * Redaction replaces the value rather than dropping the key: knowing that somebody
     * changed a password, and when, is exactly the sort of thing an audit trail exists
     * for — the value itself is precisely what it must not keep.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditableAttributes(array $attributes): array
    {
        $ignored = array_merge(
            [$this->getKeyName(), 'created_at', 'updated_at'],
            $this->auditIgnored(),
        );

        $attributes = array_diff_key($attributes, array_flip($ignored));

        foreach (array_keys($attributes) as $key) {
            if (in_array($key, $this->auditRedacted(), true)) {
                $attributes[$key] = AuditRecorder::REDACTED;
            }
        }

        return $attributes;
    }

    /**
     * Attributes kept out of the trail entirely. Override per model.
     *
     * @return list<string>
     */
    public function auditIgnored(): array
    {
        return [];
    }

    /**
     * Attributes recorded as changed but with their value masked.
     *
     * Defaults to whatever the model already hides from serialisation, which is where
     * passwords and tokens live, so a new secret is protected by default rather than
     * by remembering to list it here.
     *
     * @return list<string>
     */
    public function auditRedacted(): array
    {
        return array_values($this->getHidden());
    }
}
