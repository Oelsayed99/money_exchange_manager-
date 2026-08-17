<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Exchange\ExchangeService;
use App\Domain\Exchange\RateQuote;
use App\Domain\Money\Decimal;
use App\Enums\MovementMethod;
use App\Enums\ProfitMethod;
use App\Enums\SpreadType;
use App\Http\Requests\ExchangeRequest;
use App\Http\Requests\RateConversionRequest;
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

    /**
     * Solve a rate against two amounts, so the operator can type the two they know.
     *
     * An operator says "I am buying 100,000 USD at 3.67 to the dirham" and expects to be
     * told the dirham figure; the ledger, meanwhile, wants both amounts as facts and
     * derives the rate from them. Both are right, at different moments. This bridges
     * them, on the server, where the arithmetic is exact — the same reason the profit
     * preview is computed here rather than in the component.
     */
    public function convert(RateConversionRequest $request): JsonResponse
    {
        $baseCurrency = $request->baseCurrency();
        $quoteCurrency = $request->quoteCurrency();
        $solvedFor = $request->solvingFor();

        if ($solvedFor === 'rate') {
            $baseAmount = $baseCurrency->money($request->decimal('base_amount'));
            $quoteAmount = $quoteCurrency->money($request->decimal('quote_amount'));

            $quote = RateQuote::between($baseAmount, $quoteAmount);
            $exact = RateQuote::betweenIsExact($baseAmount, $quoteAmount);
        } else {
            $quote = RateQuote::of($baseCurrency->spec(), $quoteCurrency->spec(), $request->decimal('rate'));

            if ($solvedFor === 'quote_amount') {
                $baseAmount = $baseCurrency->money($request->decimal('base_amount'));
                $conversion = $quote->convert($baseAmount);
                $quoteAmount = $conversion->amount;
            } else {
                $quoteAmount = $quoteCurrency->money($request->decimal('quote_amount'));
                $conversion = $quote->convert($quoteAmount);
                $baseAmount = $conversion->amount;
            }

            $exact = $conversion->exact;
        }

        return response()->json([
            'solved_for' => $solvedFor,
            // Always at rate precision, whether it was typed or derived, so the caller
            // is not left comparing "51.48" against "51.480000000000" and finding them
            // different. Trimming it for display is the interface's business.
            'rate' => Decimal::padTo($quote->rate, RateQuote::SCALE),
            'base_amount' => $baseAmount->jsonSerialize(),
            'quote_amount' => $quoteAmount->jsonSerialize(),
            'exact' => $exact,
        ]);
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
