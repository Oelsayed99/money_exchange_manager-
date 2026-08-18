<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Export\CsvWriter;
use App\Domain\Export\TransactionsExport;
use App\Enums\LegRole;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\TransactionLeg;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The ledger, as a list.
 *
 * Until now the ledger had no screen of its own: a party's statement showed their side
 * of it, and nothing showed the whole. A transaction that touched no counterparty —
 * moving money between our own safes, an expense — was invisible in the interface
 * while being perfectly present in the database.
 *
 * Read-only. Corrections are reversals through PostingService, never edits, and
 * offering an edit here would suggest otherwise.
 */
final class TransactionController extends Controller
{
    /** Enough to scan; small enough that the legs of each row can be loaded with it. */
    private const int PER_PAGE = 50;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Transaction::class);

        $validated = $request->validate([
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'counterparty' => ['nullable', 'integer', Rule::exists('counterparties', 'id')],
            'currency' => ['nullable', 'string', Rule::exists('currencies', 'code')],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $transactions = $this->filtered($validated)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('transactions/index', [
            'transactions' => $this->present($transactions),
            'filters' => [
                'type' => $validated['type'] ?? null,
                'status' => $validated['status'] ?? null,
                'counterparty' => isset($validated['counterparty']) ? (int) $validated['counterparty'] : null,
                'currency' => $validated['currency'] ?? null,
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
                'search' => $validated['search'] ?? null,
            ],
            'options' => $this->options(),
        ]);
    }

    /**
     * The same list, as a spreadsheet.
     *
     * Goes through the same {@see filtered} query as the screen, so what somebody is
     * looking at is what they get. One row per leg rather than per transaction — see
     * TransactionsExport.
     */
    public function csv(Request $request, CsvWriter $writer): StreamedResponse
    {
        Gate::authorize('viewAny', Transaction::class);

        $validated = $request->validate([
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'counterparty' => ['nullable', 'integer', Rule::exists('counterparties', 'id')],
            'currency' => ['nullable', 'string', Rule::exists('currencies', 'code')],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        return $writer->response(new TransactionsExport($this->filtered($validated)));
    }

    /**
     * The list, narrowed by whichever filters apply.
     *
     * Shared by the screen and the export deliberately: two copies would be two
     * chances for an exported file to contain something the page did not show.
     *
     * @param  array<string, mixed>  $validated
     * @return Builder<Transaction>
     */
    private function filtered(array $validated): Builder
    {
        $currencyId = isset($validated['currency'])
            ? Currency::query()->where('code', $validated['currency'])->value('id')
            : null;

        return Transaction::query()
            ->with(['legs.currency', 'legs.account', 'counterparty'])
            ->when(isset($validated['type']), fn (Builder $q) => $q->where('type', $validated['type']))
            ->when(isset($validated['status']), fn (Builder $q) => $q->where('status', $validated['status']))
            ->when(isset($validated['counterparty']), fn (Builder $q) => $q->where('counterparty_id', $validated['counterparty']))
            ->when(
                $currencyId !== null,
                // Through the legs, not the transaction: an exchange has no single
                // currency, and filtering on one column would hide half of every deal.
                fn (Builder $q) => $q->whereHas('legs', fn (Builder $legs) => $legs->where('currency_id', $currencyId)),
            )
            ->when(
                isset($validated['from']),
                fn (Builder $q) => $q->where('occurred_at', '>=', Carbon::parse($validated['from'])->startOfDay()),
            )
            ->when(
                isset($validated['to']),
                fn (Builder $q) => $q->where('occurred_at', '<=', Carbon::parse($validated['to'])->endOfDay()),
            )
            ->when(isset($validated['search']), function (Builder $q) use ($validated): void {
                $term = '%'.$validated['search'].'%';

                $q->where(fn (Builder $inner) => $inner
                    ->where('reference', 'like', $term)
                    ->orWhere('description', 'like', $term));
            })
            // Newest first, and by id within a day so a page boundary cannot land in
            // the middle of two transactions sharing a timestamp.
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }

    /**
     * @param  LengthAwarePaginator<int, Transaction>  $transactions
     * @return array<string, mixed>
     */
    private function present(LengthAwarePaginator $transactions): array
    {
        return [
            'data' => array_map(
                fn (Transaction $transaction): array => $this->row($transaction),
                $transactions->items(),
            ),
            'links' => [
                'prev' => $transactions->previousPageUrl(),
                'next' => $transactions->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
                'from' => $transactions->firstItem(),
                'to' => $transactions->lastItem(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function row(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'type_label' => __('transactions.types.'.$transaction->type->value),
            'status' => $transaction->status->value,
            'status_label' => __('transactions.statuses.'.$transaction->status->value),
            'occurred_at' => $transaction->occurred_at->toDateString(),
            'counterparty' => $transaction->counterparty === null ? null : [
                'id' => $transaction->counterparty->id,
                'name' => $transaction->counterparty->name,
            ],
            'reference' => $transaction->reference,
            'description' => $transaction->description,
            'is_reversal' => $transaction->isReversal(),
            'reverses_id' => $transaction->reversal_of_transaction_id,
            // The legs are what moved. An exchange has two and they are different
            // currencies, which is exactly why there is no single amount column here.
            'legs' => $transaction->legs
                ->sortBy('sequence')
                ->map(fn (TransactionLeg $leg): array => [
                    'role' => $leg->role->value,
                    'role_label' => __('transactions.roles.'.$leg->role->value),
                    'is_inflow' => $leg->role === LegRole::Received,
                    // A string, never a JSON number (R1).
                    'amount' => $leg->amount->jsonSerialize(),
                    'account' => $leg->account?->name,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'types' => array_map(
                fn (TransactionType $type): array => [
                    'value' => $type->value,
                    'label' => __('transactions.types.'.$type->value),
                ],
                TransactionType::cases(),
            ),
            'statuses' => array_map(
                fn (TransactionStatus $status): array => [
                    'value' => $status->value,
                    'label' => __('transactions.statuses.'.$status->value),
                ],
                TransactionStatus::cases(),
            ),
            'counterparties' => Counterparty::query()->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Counterparty $c): array => ['id' => $c->id, 'name' => $c->name])
                ->all(),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('sort_order')
                ->get(['code'])
                ->map(fn (Currency $c): array => ['code' => $c->code])
                ->all(),
        ];
    }
}
