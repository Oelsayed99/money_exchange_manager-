<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CurrencyRequest;
use App\Models\Currency;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration of the currency list.
 *
 * There is no destroy action, and there will not be one. A currency is referenced by
 * ledger entries that must remain reproducible forever (Section 7); removing one would
 * orphan history. Currencies are deactivated instead.
 */
final class CurrencyController extends Controller
{
    public function index(): Response
    {
        $currencies = Currency::query()
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->map(fn (Currency $currency): array => $this->present($currency))
            ->all();

        return Inertia::render('currencies/index', [
            'currencies' => $currencies,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('currencies/form', [
            'currency' => null,
        ]);
    }

    public function store(CurrencyRequest $request): RedirectResponse
    {
        Currency::query()->create($request->validated());

        return to_route('currencies.index')->with('success', __('currencies.created'));
    }

    public function edit(Currency $currency): Response
    {
        return Inertia::render('currencies/form', [
            'currency' => $this->present($currency),
        ]);
    }

    public function update(CurrencyRequest $request, Currency $currency): RedirectResponse
    {
        $currency->update($request->validated());

        return to_route('currencies.index')->with('success', __('currencies.updated'));
    }

    /**
     * @return array{
     *     id: int, code: string, name: string, name_ar: string|null, symbol: string|null,
     *     decimal_places: int, is_active: bool, sort_order: int,
     *     sample: array{amount: string, currency: string}
     * }
     */
    private function present(Currency $currency): array
    {
        return [
            'id' => $currency->id,
            'code' => $currency->code,
            'name' => $currency->name,
            'name_ar' => $currency->name_ar,
            'symbol' => $currency->symbol,
            'decimal_places' => $currency->decimal_places,
            'is_active' => $currency->is_active,
            'sort_order' => $currency->sort_order,

            // A fixed amount shown at this currency's declared precision, so the
            // effect of that setting is visible on the page rather than being
            // something the administrator has to imagine. Nothing is rounded: the
            // value is padded out to the minimum, never cut down to it.
            //
            // Money::jsonSerialize() emits { amount: string, currency: string }. The
            // amount is a string, never a JSON number — risk R1.
            'sample' => $currency->money('1234.5')->jsonSerialize(),
        ];
    }
}
