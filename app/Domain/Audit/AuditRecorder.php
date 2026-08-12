<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes entries to the audit trail.
 *
 * A single service rather than logic scattered through model hooks, so that the
 * financial events Section 15 names — a credit settled, a partial settlement, a note
 * changed — can be recorded explicitly through the same path as ordinary row changes,
 * with the same actor resolution and the same shape.
 */
final class AuditRecorder
{
    /** Replaces a redacted value, so the fact of the change survives but the value does not. */
    public const REDACTED = '[redacted]';

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(Model $model, AuditEvent $event, ?array $old = null, ?array $new = null): AuditLog
    {
        $user = Auth::user();

        return AuditLog::query()->create([
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'event' => $event->value,
            'old_values' => $old === null || $old === [] ? null : $old,
            'new_values' => $new === null || $new === [] ? null : $new,
            'user_id' => $user?->getAuthIdentifier(),
            'actor_label' => $this->actorLabel($user),
            'source' => $this->isHttpRequest() ? 'web' : 'console',
            'ip_address' => $this->isHttpRequest() ? request()->ip() : null,
            'user_agent' => $this->isHttpRequest() ? request()->userAgent() : null,
        ]);
    }

    /**
     * Whether an HTTP request is actually being handled.
     *
     * A resolved route is the signal. The obvious alternatives do not work:
     * app()->runningInConsole() reports true under the test runner too, so it cannot
     * tell an artisan command from a request made during a test; and REQUEST_URI is
     * populated ('/') even in a test that makes no request at all. A route is only
     * bound once the router has matched one, which happens for real traffic and for
     * test requests alike, and never for a console command.
     *
     * A request that matches no route is therefore recorded as console. Nothing
     * auditable runs on a 404, so this costs nothing.
     *
     * Read from the request instance rather than the facade: the facade's @method
     * annotation declares a non-nullable Route, which is not true at runtime.
     */
    private function isHttpRequest(): bool
    {
        return request()->route() !== null;
    }

    /**
     * A human-readable snapshot of the actor, stored alongside the id.
     *
     * The id alone stops meaning anything once the user row is gone, and the audit
     * trail has to remain readable long after people leave.
     */
    private function actorLabel(?object $user): ?string
    {
        if ($user === null) {
            return $this->isHttpRequest() ? null : 'console';
        }

        foreach (['email', 'name'] as $attribute) {
            $value = $user->{$attribute} ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
