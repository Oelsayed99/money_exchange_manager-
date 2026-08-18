<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Money\Money;
use App\Domain\Statement\CounterpartyStatement;
use App\Domain\Statement\StatementBuilder;
use App\Domain\Statement\StatementRow;
use App\Enums\BalanceBucket;
use App\Enums\LedgerOwnerType;
use App\Enums\StatementMode;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A counterparty's account with the business — the document that replaces the sheet.
 *
 * The mode is read here and passed into the builder, not applied afterwards. In Client
 * mode the profit columns are never selected, so the figures are absent from the props
 * rather than merely unrendered. Inertia serialises props into the HTML document; a
 * profit hidden by a React condition would still be in the page source.
 */
final class CounterpartyStatementController extends Controller
{
    public function __construct(private readonly StatementBuilder $statements) {}

    public function show(Request $request, Counterparty $counterparty): Response
    {
        Gate::authorize('view', $counterparty);

        $currencies = $this->currenciesTraded($counterparty);

        $validated = $request->validate([
            'currency' => ['nullable', 'string', Rule::in($currencies->pluck('code')->all())],
            'mode' => ['nullable', Rule::enum(StatementMode::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $currency = $currencies->firstWhere('code', $validated['currency'] ?? null)
            ?? $currencies->first();

        // A party with no ledger activity has nothing to state. Say so plainly rather
        // than rendering an empty table that looks like a settled account.
        if (! $currency instanceof Currency) {
            return Inertia::render('counterparties/statement', [
                'counterparty' => ['id' => $counterparty->id, 'name' => $counterparty->name],
                'currencies' => [],
                'statement' => null,
                'filters' => ['currency' => null, 'mode' => StatementMode::Client->value, 'from' => null, 'to' => null],
                'modes' => $this->modes(),
                'bucketLabels' => $this->bucketLabels(),
            ]);
        }

        $mode = StatementMode::tryFrom($validated['mode'] ?? '') ?? StatementMode::Client;
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : null;
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : null;

        $statement = $this->statements->build($counterparty, $currency, $mode, $from, $to);

        return Inertia::render('counterparties/statement', [
            'counterparty' => ['id' => $counterparty->id, 'name' => $counterparty->name],
            'currencies' => $currencies->map(fn (Currency $c): array => ['id' => $c->id, 'code' => $c->code])->values()->all(),
            'statement' => $this->present($statement),
            'filters' => [
                'currency' => $currency->code,
                'mode' => $mode->value,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'modes' => $this->modes(),
            'bucketLabels' => $this->bucketLabels(),
        ]);
    }

    /**
     * The currencies this party has actually dealt in.
     *
     * Taken from their ledger accounts rather than the full currency list: offering a
     * statement in a currency they have never touched invites the reader to conclude
     * something from a page of zeros.
     *
     * @return Collection<int, Currency>
     */
    private function currenciesTraded(Counterparty $counterparty): Collection
    {
        $ids = LedgerAccount::query()
            ->where('owner_type', LedgerOwnerType::Counterparty->value)
            ->where('owner_id', $counterparty->getKey())
            ->pluck('currency_id')
            ->unique()
            ->all();

        return Currency::query()
            ->whereIn('id', $ids)
            ->orderBy('sort_order')
            ->get()
            ->values();
    }

    /** @return array<string, mixed> */
    private function present(CounterpartyStatement $statement): array
    {
        return [
            'currency' => $statement->currency->code,
            'decimal_places' => $statement->currency->decimal_places,
            'mode' => $statement->mode->value,
            'shows_profit' => $statement->mode->showsProfit(),
            'buckets' => array_map(fn (BalanceBucket $b): string => $b->value, $statement->buckets),
            'rows' => array_map(fn (StatementRow $row): array => $this->presentRow($row), $statement->rows),
            'opening' => $this->presentBalances($statement->opening, $statement->buckets),
            'closing' => $this->presentBalances($statement->closing, $statement->buckets),
            'total_in' => $this->presentBalances($statement->totalIn, $statement->buckets),
            'total_out' => $this->presentBalances($statement->totalOut, $statement->buckets),
            // Absent entirely in Client mode, not merely empty.
            'profit' => $statement->mode->showsProfit()
                ? array_map(fn (Money $m): array => $m->jsonSerialize(), $statement->profit)
                : null,
            'declared_opening' => array_map(fn (Money $m): array => $m->jsonSerialize(), $statement->declaredOpening),
        ];
    }

    /** @return array<string, mixed> */
    private function presentRow(StatementRow $row): array
    {
        return [
            'transaction_id' => $row->transactionId,
            'type' => $row->type->value,
            'type_label' => __('transactions.types.'.$row->type->value),
            'occurred_at' => $row->occurredAt->toDateString(),
            'reference' => $row->reference,
            'description' => $row->description,
            'bucket' => $row->bucket->value,
            // Every amount a string, never a JSON number (R1).
            'in' => $row->in?->jsonSerialize(),
            'out' => $row->out?->jsonSerialize(),
            'balance_after' => $row->balanceAfter->jsonSerialize(),
            'profit' => $row->profit?->jsonSerialize(),
        ];
    }

    /**
     * @param  array<string, Money>  $balances
     * @param  list<BalanceBucket>  $buckets
     * @return array<string, array{amount: string, currency: string}>
     */
    private function presentBalances(array $balances, array $buckets): array
    {
        $presented = [];

        foreach ($buckets as $bucket) {
            $amount = $balances[$bucket->value] ?? null;

            if ($amount instanceof Money) {
                $presented[$bucket->value] = $amount->jsonSerialize();
            }
        }

        return $presented;
    }

    /** @return list<array{value: string, label: string}> */
    private function modes(): array
    {
        return array_map(
            fn (StatementMode $mode): array => ['value' => $mode->value, 'label' => __('statements.modes.'.$mode->value)],
            StatementMode::cases(),
        );
    }

    /**
     * Each bucket's name and, separately, how a position in it reads on a page.
     *
     * "Credit / trust" names the bucket; "Client credit" is what a balance in it means
     * to whoever is holding the statement. The second is what stops the reader having
     * to interpret a sign.
     *
     * @return array<string, array{label: string, position: string}>
     */
    private function bucketLabels(): array
    {
        $labels = [];

        foreach (BalanceBucket::cases() as $bucket) {
            $labels[$bucket->value] = [
                'label' => __('counterparties.buckets.'.$bucket->value),
                'position' => __('statements.positions.'.$bucket->value),
            ];
        }

        return $labels;
    }
}
