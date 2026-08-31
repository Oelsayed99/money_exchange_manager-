<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Counterparty\OpeningPositionRecorder;
use App\Domain\Reporting\CounterpartyStanding;
use App\Domain\Reporting\CounterpartyStandings;
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
 * The two sides of a relationship are never added together. Section 5 forbids
 * collapsing custody, receivable and payable into one figure, and a controller that
 * helpfully netted them for display would reintroduce exactly that.
 *
 * A *side* is summed for the list — our money with them against their money with us —
 * and the four buckets travel alongside it for the drill-down. See
 * {@see CounterpartyStanding} for why one of those is a summary and the other is a
 * loss of information.
 */
final class CounterpartyController extends Controller
{
    public function __construct(
        private readonly CounterpartyStandings $standings,
        private readonly OpeningPositionRecorder $openings,
    ) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', Counterparty::class);

        $parties = Counterparty::query()
            ->with(['preferredCurrency', 'openingBalances.currency'])
            ->orderBy('name')
            ->get();

        // One query for the whole list rather than one per row.
        $standings = $this->standings->forParties(array_map(intval(...), array_values($parties->modelKeys())));

        return Inertia::render('counterparties/index', [
            'counterparties' => $parties
                ->map(fn (Counterparty $party): array => [
                    ...$this->present($party),
                    'standings' => $this->presentStandings($standings[$party->getKey()] ?? []),
                ])
                ->all(),
        ]);
    }

    /**
     * @param  list<CounterpartyStanding>  $standings
     * @return list<array<string, mixed>>
     */
    private function presentStandings(array $standings): array
    {
        return array_map(
            fn (CounterpartyStanding $standing): array => [
                'code' => $standing->code,
                // A string, never a JSON number (R1). Signed: positive means they owe us.
                'balance' => $standing->balance->toDisplayString(),
            ],
            $standings,
        );
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

            $this->openings->sync($party, $request->validated('positions', []), now());
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

            $this->openings->sync($counterparty, $request->validated('positions', []), now());
        });

        return to_route('counterparties.index')->with('success', __('counterparties.updated'));
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
            'availableCurrencies' => Currency::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'code'])
                ->map(fn (Currency $currency): array => ['id' => $currency->id, 'code' => $currency->code])
                ->all(),
        ];
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
                    'currency_id' => $position->currency_id,
                    'code' => $position->currency?->code,
                    // String, never a JSON number (R1).
                    'amount' => $position->amount?->toDisplayString() ?? '0',
                    // What the ledger has not been told about yet. Zero for anything
                    // saved since opening positions started posting.
                    'unposted' => $position->amount
                        ?->minus($position->posted_amount ?? $position->amount)
                        ->toDisplayString() ?? '0',
                ])
                ->values()
                ->all(),
        ];
    }
}
