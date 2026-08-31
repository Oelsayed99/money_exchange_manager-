<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\Owned;
use App\Enums\AuditEvent;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reading the audit trail.
 *
 * The trail has been written since Phase 1 and could not be read from anywhere in the
 * interface — which makes it evidence nobody can look at. A record that is only ever
 * written is a record nobody checks, and one nobody checks is one nobody notices has
 * stopped working.
 *
 * Read-only, because the trail is append-only and the database enforces it.
 */
final class AuditLogController extends Controller
{
    private const int PER_PAGE = 50;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AuditLog::class);

        $validated = $request->validate([
            'event' => ['nullable', Rule::enum(AuditEvent::class)],
            'type' => ['nullable', 'string', Rule::in($this->auditedTypes()->keys()->all())],
            'user' => ['nullable', 'integer', Owned::exists('users', 'id')],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $logs = AuditLog::query()
            ->when(isset($validated['event']), fn (Builder $q) => $q->where('event', $validated['event']))
            ->when(isset($validated['type']), fn (Builder $q) => $q->where('auditable_type', $validated['type']))
            ->when(isset($validated['user']), fn (Builder $q) => $q->where('user_id', $validated['user']))
            ->when(
                isset($validated['from']),
                fn (Builder $q) => $q->where('created_at', '>=', Carbon::parse($validated['from'])->startOfDay()),
            )
            ->when(
                isset($validated['to']),
                fn (Builder $q) => $q->where('created_at', '<=', Carbon::parse($validated['to'])->endOfDay()),
            )
            ->when(isset($validated['search']), function (Builder $q) use ($validated): void {
                $term = '%'.$validated['search'].'%';

                // The actor is searched by the label stored on the row, not by joining
                // users: the label is a snapshot that outlives the account, which is
                // the point of storing it (see the audit ADR).
                $q->where(fn (Builder $inner) => $inner
                    ->where('actor_label', 'like', $term)
                    ->orWhere('auditable_id', 'like', $term));
            })
            // Newest first, id as the tiebreak so a page boundary cannot land between
            // two rows written in the same second.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('audit/index', [
            'logs' => $this->present($logs),
            'filters' => [
                'event' => $validated['event'] ?? null,
                'type' => $validated['type'] ?? null,
                'user' => isset($validated['user']) ? (int) $validated['user'] : null,
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
                'search' => $validated['search'] ?? null,
            ],
            'options' => [
                'events' => array_map(
                    fn (AuditEvent $event): array => [
                        'value' => $event->value,
                        'label' => __('audit.events.'.$event->value),
                    ],
                    AuditEvent::cases(),
                ),
                'types' => $this->auditedTypes()
                    ->map(fn (string $label, string $class): array => ['value' => $class, 'label' => $label])
                    ->values()
                    ->all(),
                'users' => User::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])
                    ->all(),
            ],
        ]);
    }

    /**
     * @param  LengthAwarePaginator<int, AuditLog>  $logs
     * @return array<string, mixed>
     */
    private function present(LengthAwarePaginator $logs): array
    {
        return [
            'data' => array_map(fn (AuditLog $log): array => $this->row($log), $logs->items()),
            'links' => ['prev' => $logs->previousPageUrl(), 'next' => $logs->nextPageUrl()],
            'meta' => [
                'total' => $logs->total(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function row(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'recorded_at' => $log->created_at->toDateTimeString(),
            'event' => $log->event,
            'event_label' => __('audit.events.'.$log->event),
            'type' => $this->auditedTypes()->get($log->auditable_type, class_basename($log->auditable_type)),
            'record_id' => $log->auditable_id,
            // The label rather than the relation: it is a snapshot taken at the time,
            // and it survives the account being deleted.
            'actor' => $log->actor_label,
            'source' => $log->source,
            'ip_address' => $log->ip_address,
            'changes' => $this->changes($log),
        ];
    }

    /**
     * The fields that actually changed, old beside new.
     *
     * Only what differs. An update touching one column writes the whole attribute set
     * on some paths, and listing thirty unchanged fields to find the one that moved is
     * how somebody stops reading an audit trail.
     *
     * Redacted values pass straight through as `[redacted]` — the recorder replaces
     * secrets rather than omitting them, so the trail says a password changed without
     * saying what to.
     *
     * @return list<array{field: string, old: string|null, new: string|null}>
     */
    private function changes(AuditLog $log): array
    {
        $old = $log->old_values ?? [];
        $new = $log->new_values ?? [];

        $fields = array_unique([...array_keys($old), ...array_keys($new)]);
        sort($fields);

        $changes = [];

        foreach ($fields as $field) {
            $before = $this->scalar($old[$field] ?? null);
            $after = $this->scalar($new[$field] ?? null);

            if ($before === $after) {
                continue;
            }

            $changes[] = ['field' => $field, 'old' => $before, 'new' => $after];
        }

        return $changes;
    }

    /** Render a stored value as text without pretending a structure is a string. */
    private function scalar(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE) ?: '',
        };
    }

    /**
     * The models that carry an audit trail, by class name.
     *
     * Listed rather than discovered: the filter offers what exists, and a class that
     * stops being audited should disappear from the filter rather than linger as an
     * option that returns nothing.
     *
     * @return Collection<string, string>
     */
    private function auditedTypes(): Collection
    {
        return collect([
            Transaction::class => __('audit.types.transaction'),
            Reconciliation::class => __('audit.types.reconciliation'),
            Account::class => __('audit.types.account'),
            Counterparty::class => __('audit.types.counterparty'),
            Currency::class => __('audit.types.currency'),
            User::class => __('audit.types.user'),
        ]);
    }
}
