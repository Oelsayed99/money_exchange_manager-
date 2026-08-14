<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BalanceBucket;
use App\Enums\CounterpartyType;
use App\Http\Requests\CounterpartyRequest;
use App\Models\Counterparty;
use App\Models\CounterpartyOpeningBalance;
use App\Models\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Parties the business deals with.
 *
 * Positions are always sent to the client grouped by bucket, never summed. Section 5
 * forbids collapsing custody, receivable and payable into one figure, and a controller
 * that helpfully netted them for display would reintroduce exactly that.
 */
final class CounterpartyController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Counterparty::class);

        $counterparties = Counterparty::query()
            ->with(['preferredCurrency', 'openingBalances.currency'])
            ->orderBy('name')
            ->get()
            ->map(fn (Counterparty $party): array => $this->present($party))
            ->all();

        return Inertia::render('counterparties/index', [
            'counterparties' => $counterparties,
            'buckets' => $this->buckets(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Counterparty::class);

        return Inertia::render('counterparties/form', [
            'counterparty' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(CounterpartyRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $party = Counterparty::query()->create($request->safe()->except('positions'));

            $this->syncPositions($party, $request->validated('positions', []));
        });

        return to_route('counterparties.index')->with('success', __('counterparties.created'));
    }

    public function edit(Counterparty $counterparty): Response
    {
        Gate::authorize('update', $counterparty);

        $counterparty->load(['preferredCurrency', 'openingBalances.currency']);

        return Inertia::render('counterparties/form', [
            'counterparty' => $this->present($counterparty),
            ...$this->formOptions(),
        ]);
    }

    public function update(CounterpartyRequest $request, Counterparty $counterparty): RedirectResponse
    {
        DB::transaction(function () use ($request, $counterparty): void {
            $counterparty->update($request->safe()->except('positions'));

            $this->syncPositions($counterparty, $request->validated('positions', []));
        });

        return to_route('counterparties.index')->with('success', __('counterparties.updated'));
    }

    /**
     * @param  array<int, array{bucket: string, currency_id: int|string, amount: string}>  $rows
     */
    private function syncPositions(Counterparty $party, array $rows): void
    {
        $keep = [];

        foreach ($rows as $row) {
            $position = $party->openingBalances()->updateOrCreate(
                ['bucket' => $row['bucket'], 'currency_id' => (int) $row['currency_id']],
                ['amount' => $row['amount']],
            );

            $keep[] = $position->getKey();
        }

        // Rows removed from the form are removed from the record. These are declared
        // opening positions, not ledger entries — the ledger's own immutability rules
        // arrive in Phase 3 and will govern anything posted from them.
        $party->openingBalances()->whereNotIn('id', $keep === [] ? [0] : $keep)->delete();
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'counterpartyTypes' => array_map(
                fn (CounterpartyType $type): array => [
                    'value' => $type->value,
                    'label' => __('counterparties.types.'.$type->value),
                ],
                CounterpartyType::cases(),
            ),
            'buckets' => $this->buckets(),
            'availableCurrencies' => Currency::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'code'])
                ->map(fn (Currency $currency): array => ['id' => $currency->id, 'code' => $currency->code])
                ->all(),
        ];
    }

    /** @return list<array{value: string, label: string, hint: string, isAsset: bool, mirror: string}> */
    private function buckets(): array
    {
        return array_map(
            fn (BalanceBucket $bucket): array => [
                'value' => $bucket->value,
                'label' => __('counterparties.buckets.'.$bucket->value),
                'hint' => __('counterparties.bucket_hints.'.$bucket->value),
                'isAsset' => $bucket->isAsset(),
                'mirror' => $bucket->mirror()->value,
            ],
            BalanceBucket::cases(),
        );
    }

    /** @return array<string, mixed> */
    private function present(Counterparty $party): array
    {
        return [
            'id' => $party->id,
            'name' => $party->name,
            'type' => $party->type->value,
            'type_label' => __('counterparties.types.'.$party->type->value),
            'phone' => $party->phone,
            'email' => $party->email,
            'country' => $party->country,
            'preferred_currency_id' => $party->preferred_currency_id,
            'preferred_currency_code' => $party->preferredCurrency?->code,
            'is_active' => $party->is_active,
            'positions' => $party->openingBalances
                ->map(fn (CounterpartyOpeningBalance $position): array => [
                    'bucket' => $position->bucket->value,
                    'currency_id' => $position->currency_id,
                    'code' => $position->currency?->code,
                    // String, never a JSON number (R1).
                    'amount' => $position->amount?->toDisplayString() ?? '0',
                ])
                ->values()
                ->all(),
        ];
    }
}
