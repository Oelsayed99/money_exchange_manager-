<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Money\Money;
use App\Enums\MovementMethod;
use App\Enums\TransactionType;
use App\Http\Requests\MovementRequest;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\LedgerBalance;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recording an ordinary movement.
 *
 * Everything the ledger posts except a currency exchange: credit in and out, lending
 * and borrowing either way, settlements, transfers between our own locations, fees and
 * expenses. Until this existed the only movement anybody could record through the
 * interface was an exchange, and the rest of the ledger was reachable only from code.
 */
final class MovementController extends Controller
{
    public function __construct(
        private readonly PostingRules $rules,
        private readonly PostingService $posting,
        private readonly LedgerAccountResolver $accounts,
    ) {}

    public function create(): Response
    {
        Gate::authorize('create', Transaction::class);

        return Inertia::render('movements/create', $this->formOptions());
    }

    /**
     * Where this party stands, and where this movement would leave them.
     *
     * Shown before anything is recorded, because "they owe me" and "I am holding their
     * money" are the same figure with a different sign, and a sign is the easiest thing
     * on a screen to misread. Both are returned, and so is whether the relationship
     * turns over.
     */
    public function positions(Request $request): JsonResponse
    {
        Gate::authorize('create', Transaction::class);

        $validated = $request->validate([
            'counterparty_id' => ['required', 'integer', Rule::exists('counterparties', 'id')],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')],
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'amount' => ['nullable', 'string'],
        ]);

        $counterparty = Counterparty::query()->findOrFail((int) $validated['counterparty_id']);
        $currency = Currency::query()->findOrFail((int) $validated['currency_id']);

        $balance = $this->currentBalance($counterparty, $currency);
        $type = isset($validated['type']) ? TransactionType::tryFrom((string) $validated['type']) : null;
        $effect = $type?->clientEffect();

        $after = null;

        if ($effect !== null && isset($validated['amount']) && $validated['amount'] !== '') {
            $amount = $currency->money((string) $validated['amount']);

            $after = $effect->increases()
                ? $balance->plus($amount)
                : $balance->minus($amount);
        }

        return response()->json([
            // Signed, both of them: positive means they owe us.
            'balance' => $balance->jsonSerialize(),
            'after' => $after?->jsonSerialize(),
            // Which way the relationship runs once this is recorded. Said outright,
            // because a minus sign in front of a figure is the easiest thing to miss.
            'they_owe_us' => ($after ?? $balance)->isPositive(),
            'turns_over' => $after !== null && $balance->isPositive() !== $after->isPositive() && ! $after->isZero(),
        ]);
    }

    public function store(MovementRequest $request): RedirectResponse
    {
        Gate::authorize('post', Transaction::class);

        $this->posting->post($this->rules->build($request->toTransactionInput()));

        return to_route('movements.create')->with('success', __('movements.recorded'));
    }

    /**
     * Where this party stands with us in this currency, right now.
     *
     * One signed figure: positive means they owe us, negative that we are holding
     * theirs. Zero is a real answer and is returned as one — "nothing either way" is
     * different from "not checked".
     */
    private function currentBalance(Counterparty $counterparty, Currency $currency): Money
    {
        $account = $this->accounts->forCounterparty($counterparty, $currency);

        return LedgerBalance::query()
            ->where('ledger_account_id', $account->id)
            ->first()
            ?->confirmed() ?? Money::zero($currency->spec());
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        $types = array_values(array_filter(
            TransactionType::cases(),
            fn (TransactionType $type): bool => $type->recordableByHand(),
        ));

        return [
            // Each type carries what it needs, so the form can show and require the
            // right fields without a second copy of the rules living in React.
            'types' => array_map(
                fn (TransactionType $type): array => [
                    'value' => $type->value,
                    'label' => __('transactions.types.'.$type->value),
                    'needsCounterparty' => $type->needsCounterparty(),
                    'needsDestinationAccount' => $type->needsDestinationAccount(),
                    // Whether the money may be recorded in a currency other than the
                    // one that moved — only the two client movements do that.
                    'mayConvert' => $type->mayConvert(),
                    'increases' => $type->clientEffect()?->increases(),
                ],
                $types,
            ),
            'accounts' => Account::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Account $a): array => ['id' => $a->id, 'name' => $a->name])
                ->all(),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('sort_order')
                ->get(['id', 'code'])
                ->map(fn (Currency $c): array => ['id' => $c->id, 'code' => $c->code])
                ->all(),
            'counterparties' => Counterparty::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Counterparty $c): array => ['id' => $c->id, 'name' => $c->name])
                ->all(),
            'methods' => array_map(
                fn (MovementMethod $m): array => ['value' => $m->value, 'label' => __('transactions.methods.'.$m->value)],
                MovementMethod::cases(),
            ),
        ];
    }
}
