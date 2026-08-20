<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ledger\LedgerAccountResolver;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Money\Money;
use App\Enums\BalanceBucket;
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
     * A counterparty's four positions, and what this movement would do to one of them.
     *
     * Shown before anything is recorded, because "add 500,000 to their credit" and
     * "reduce what they owe by 500,000" are easy to confuse at the keyboard and
     * impossible to confuse once the four positions are on screen next to each other.
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

        $current = $this->currentPositions($counterparty, $currency);
        $type = isset($validated['type']) ? TransactionType::tryFrom((string) $validated['type']) : null;
        $effect = $type?->bucketEffect();

        $after = null;
        $warning = null;

        if ($effect !== null && isset($validated['amount']) && $validated['amount'] !== '') {
            $amount = $currency->money((string) $validated['amount']);
            $balance = $current[$effect->bucket->value] ?? Money::zero($currency->spec());

            $result = $effect->increases ? $balance->plus($amount) : $balance->minus($amount);

            $after = [
                'bucket' => $effect->bucket->value,
                'amount' => $result->jsonSerialize(),
                'increases' => $effect->increases,
            ];

            // The owner's decision (docs/posting-rules.md §9.4): a credit balance may go
            // negative, always allowed. A warning, never a block — a negative credit is
            // really them owing us, and whether to reclassify it is a judgement the
            // person at the counter is better placed to make than this code is.
            if ($result->isNegative()) {
                $warning = $effect->bucket->value;
            }
        }

        return response()->json([
            'positions' => array_map(fn (Money $m): array => $m->jsonSerialize(), $current),
            'after' => $after,
            'negative_warning' => $warning,
        ]);
    }

    public function store(MovementRequest $request): RedirectResponse
    {
        Gate::authorize('post', Transaction::class);

        $this->posting->post($this->rules->build($request->toTransactionInput()));

        return to_route('movements.create')->with('success', __('movements.recorded'));
    }

    /**
     * Every position this party holds in this currency, including the zeroes.
     *
     * Zeroes included on purpose: a bucket showing 0.00 says "nothing here", where a
     * missing row leaves the reader to wonder whether it was checked.
     *
     * @return array<string, Money>
     */
    private function currentPositions(Counterparty $counterparty, Currency $currency): array
    {
        $positions = [];

        foreach (BalanceBucket::cases() as $bucket) {
            $account = $this->accounts->forBucket($bucket, $counterparty, $currency);

            $balance = LedgerBalance::query()
                ->where('ledger_account_id', $account->id)
                ->first();

            $positions[$bucket->value] = $balance?->confirmed() ?? Money::zero($currency->spec());
        }

        return $positions;
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
                    'needsBucket' => $type->needsBucket(),
                    'bucket' => $type->bucketEffect()?->bucket->value,
                    'increases' => $type->bucketEffect()?->increases,
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
            'buckets' => array_map(
                fn (BalanceBucket $b): array => [
                    'value' => $b->value,
                    'label' => __('counterparties.buckets.'.$b->value),
                    'position' => __('statements.positions.'.$b->value),
                ],
                BalanceBucket::cases(),
            ),
            'methods' => array_map(
                fn (MovementMethod $m): array => ['value' => $m->value, 'label' => __('transactions.methods.'.$m->value)],
                MovementMethod::cases(),
            ),
        ];
    }
}
