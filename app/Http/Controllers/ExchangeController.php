<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Exchange\ExchangeService;
use App\Enums\MovementMethod;
use App\Enums\ProfitMethod;
use App\Enums\SpreadType;
use App\Http\Requests\ExchangeRequest;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recording a currency exchange.
 *
 * The profit preview is computed here, on the server, by the same calculator that runs
 * when the deal is recorded. Section 16 keeps financial calculation out of React
 * components, and the practical reason is stronger than the rule: two implementations
 * of the arithmetic would be free to disagree, and the operator would be shown one
 * number and charged another.
 */
final class ExchangeController extends Controller
{
    public function __construct(private readonly ExchangeService $exchange) {}

    public function create(): Response
    {
        Gate::authorize('create', Transaction::class);

        return Inertia::render('exchange/create', $this->formOptions());
    }

    /** The live calculation. Records nothing. */
    public function preview(ExchangeRequest $request): JsonResponse
    {
        $breakdown = $this->exchange->preview($request->toExchangeInput());

        return response()->json($breakdown->jsonSerialize());
    }

    public function store(ExchangeRequest $request): RedirectResponse
    {
        Gate::authorize('post', Transaction::class);

        $input = $request->toExchangeInput();

        // Section 3: warn before saving an unexpected loss. Checked on the server so
        // the warning cannot be bypassed by anything that skips the interface.
        if ($this->exchange->preview($input)->isLoss() && ! $request->lossConfirmed()) {
            return back()
                ->withInput()
                ->withErrors(['confirm_loss' => __('transactions.loss.required')]);
        }

        $this->exchange->record($input);

        return to_route('exchange.create')->with('success', __('transactions.exchange.recorded'));
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'currencies' => Currency::query()->where('is_active', true)->orderBy('sort_order')
                ->get(['id', 'code', 'decimal_places'])
                ->map(fn (Currency $c): array => ['id' => $c->id, 'code' => $c->code, 'decimal_places' => $c->decimal_places])
                ->all(),
            'accounts' => Account::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Account $a): array => ['id' => $a->id, 'name' => $a->name])
                ->all(),
            'counterparties' => Counterparty::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Counterparty $c): array => ['id' => $c->id, 'name' => $c->name])
                ->all(),
            'profitMethods' => array_map(
                fn (ProfitMethod $m): array => [
                    'value' => $m->value,
                    'label' => __('transactions.profit_methods.'.$m->value),
                    'needsCostRate' => $m->needsCostRate(),
                    'isStatedDirectly' => $m->isStatedDirectly(),
                ],
                ProfitMethod::cases(),
            ),
            // Every spread carries what it means. Section 3: 0.02 is not always 2%.
            'spreadTypes' => array_map(
                fn (SpreadType $t): array => ['value' => $t->value, 'label' => __('transactions.spread_types.'.$t->value)],
                SpreadType::cases(),
            ),
            'methods' => array_map(
                fn (MovementMethod $m): array => ['value' => $m->value, 'label' => __('transactions.methods.'.$m->value)],
                MovementMethod::cases(),
            ),
        ];
    }
}
