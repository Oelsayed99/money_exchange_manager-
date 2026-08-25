<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Money\Decimal;
use App\Domain\Money\Money;
use App\Domain\Reconciliation\ReconciliationService;
use App\Enums\ReconciliationStatus;
use App\Models\Account;
use App\Models\Currency;
use App\Models\Reconciliation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reconciling the ledger against the world.
 *
 * `ledger:verify` proves the ledger agrees with itself. This is the other half: the
 * cash actually counted in a safe, the balance a bank actually reports.
 *
 * Nothing here writes a balance. A difference that turns out to be a real error is
 * corrected by posting an adjustment through the ledger like any other movement, and
 * the reconciliation records which transaction did it.
 */
final class ReconciliationController extends Controller
{
    public function __construct(private readonly ReconciliationService $reconciliations) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Reconciliation::class);

        $validated = $request->validate([
            'account' => ['nullable', 'integer', Rule::exists('accounts', 'id')],
            'currency' => ['nullable', 'string', Rule::exists('currencies', 'code')],
            'status' => ['nullable', Rule::enum(ReconciliationStatus::class)],
        ]);

        $records = Reconciliation::query()
            ->with(['account', 'currency', 'resolver'])
            ->when(isset($validated['account']), fn ($q) => $q->where('account_id', $validated['account']))
            ->when(
                isset($validated['currency']),
                fn ($q) => $q->whereHas('currency', fn ($c) => $c->where('code', $validated['currency'])),
            )
            ->when(isset($validated['status']), fn ($q) => $q->where('status', $validated['status']))
            ->orderByDesc('as_of')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        // Drift for the whole page in one query rather than one per row.
        $drift = $this->reconciliations->driftFor($records);

        return Inertia::render('reconciliations/index', [
            'reconciliations' => $records->map(fn (Reconciliation $r): array => $this->present($r, $drift[$r->id] ?? null))->all(),
            'filters' => [
                'account' => isset($validated['account']) ? (int) $validated['account'] : null,
                'currency' => $validated['currency'] ?? null,
                'status' => $validated['status'] ?? null,
            ],
            'options' => $this->options(),
            'can' => ['manage' => $request->user()?->can('reconciliations.manage') ?? false],
        ]);
    }

    /**
     * What the ledger says, so the operator can compare before committing a count.
     *
     * Returned rather than pre-filled into the form. Showing the expected figure in the
     * box somebody is about to type their count into invites them to agree with it,
     * which is the one thing a reconciliation must not encourage.
     */
    public function expected(Request $request): JsonResponse
    {
        Gate::authorize('create', Reconciliation::class);

        $validated = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')],
            'as_of' => ['required', 'date'],
        ]);

        $balance = $this->reconciliations->ledgerBalanceAsOf(
            Account::query()->findOrFail((int) $validated['account_id']),
            Currency::query()->findOrFail((int) $validated['currency_id']),
            Carbon::parse((string) $validated['as_of']),
        );

        return response()->json(['ledger_amount' => $balance->jsonSerialize()]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Reconciliation::class);

        $validated = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')],
            'as_of' => ['required', 'date', 'before_or_equal:today'],
            'counted_amount' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [], ['counted_amount' => __('reconciliations.counted')]);

        $counted = (string) $validated['counted_amount'];

        if (! Decimal::isValid($counted)) {
            return back()->withInput()->withErrors([
                'counted_amount' => __('validation.numeric', ['attribute' => __('reconciliations.counted')]),
            ]);
        }

        $account = Account::query()->findOrFail((int) $validated['account_id']);
        $currency = Currency::query()->findOrFail((int) $validated['currency_id']);

        // One count per account, currency and day. Two records for one day would leave
        // nobody able to say which was the count.
        $exists = Reconciliation::query()
            ->where('account_id', $account->getKey())
            ->where('currency_id', $currency->getKey())
            ->whereDate('as_of', Carbon::parse((string) $validated['as_of'])->toDateString())
            ->exists();

        if ($exists) {
            return back()->withInput()->withErrors(['as_of' => __('reconciliations.already_counted')]);
        }

        $this->reconciliations->record(
            $account,
            $currency,
            Carbon::parse((string) $validated['as_of']),
            $currency->money($counted),
            $request->user(),
            $validated['note'] ?? null,
        );

        return to_route('reconciliations.index')->with('success', __('reconciliations.recorded'));
    }

    public function resolve(Request $request, Reconciliation $reconciliation): RedirectResponse
    {
        Gate::authorize('resolve', $reconciliation);

        $validated = $request->validate([
            'resolution' => ['required', 'string', 'max:2000'],
            'adjustment_transaction_id' => ['nullable', 'integer', Rule::exists('transactions', 'id')],
        ]);

        if ($reconciliation->isBalanced()) {
            return back()->withErrors(['resolution' => __('reconciliations.nothing_to_explain')]);
        }

        $this->reconciliations->resolve(
            $reconciliation,
            (string) $validated['resolution'],
            $request->user(),
            isset($validated['adjustment_transaction_id']) ? (int) $validated['adjustment_transaction_id'] : null,
        );

        return back()->with('success', __('reconciliations.resolved'));
    }

    /** @param  Money|null  $drift  null when the ledger has not moved since the count
     * @return array<string, mixed>
     */
    private function present(Reconciliation $reconciliation, ?Money $drift): array
    {
        return [
            'id' => $reconciliation->id,
            'as_of' => $reconciliation->as_of->toDateString(),
            'account' => $reconciliation->account?->name,
            'currency' => $reconciliation->currency?->code,
            // Strings, as everywhere (R1).
            'counted' => $reconciliation->counted_amount->jsonSerialize(),
            'ledger' => $reconciliation->ledger_amount->jsonSerialize(),
            'difference' => $reconciliation->difference->jsonSerialize(),
            'status' => $reconciliation->status->value,
            'status_label' => __('reconciliations.statuses.'.$reconciliation->status->value),
            'is_surplus' => $reconciliation->isSurplus(),
            'note' => $reconciliation->note,
            'resolution' => $reconciliation->resolution,
            'resolved_by' => $reconciliation->resolver?->name,
            'resolved_at' => $reconciliation->resolved_at?->toDateString(),
            'adjustment_transaction_id' => $reconciliation->adjustment_transaction_id,
            // Present means something dated on or before this day was posted after the
            // count: the reconciliation no longer describes the ledger.
            'drift' => $drift?->jsonSerialize(),
        ];
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'accounts' => Account::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Account $a): array => ['id' => $a->id, 'name' => $a->name])
                ->all(),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('sort_order')
                ->get(['id', 'code'])
                ->map(fn (Currency $c): array => ['id' => $c->id, 'code' => $c->code])
                ->all(),
            'statuses' => array_map(
                fn (ReconciliationStatus $s): array => [
                    'value' => $s->value,
                    'label' => __('reconciliations.statuses.'.$s->value),
                ],
                ReconciliationStatus::cases(),
            ),
        ];
    }
}
