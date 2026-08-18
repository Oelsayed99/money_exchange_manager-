<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Export\CsvWriter;
use App\Domain\Export\StatementExport;
use App\Domain\Money\Money;
use App\Domain\Statement\CounterpartyStatement;
use App\Domain\Statement\StatementBuilder;
use App\Domain\Statement\StatementFilename;
use App\Domain\Statement\StatementPdf;
use App\Domain\Statement\StatementRow;
use App\Enums\BalanceBucket;
use App\Enums\LedgerOwnerType;
use App\Enums\StatementMode;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    public function __construct(
        private readonly StatementBuilder $statements,
        private readonly StatementPdf $pdf,
        private readonly StatementFilename $filenames,
    ) {}

    public function show(Request $request, Counterparty $counterparty): Response
    {
        Gate::authorize('view', $counterparty);

        $currencies = $this->currenciesTraded($counterparty);
        $statement = $this->resolve($request, $counterparty, $currencies);

        // A party with no ledger activity has nothing to state. Say so plainly rather
        // than rendering an empty table that looks like a settled account.
        if ($statement === null) {
            return Inertia::render('counterparties/statement', [
                'counterparty' => ['id' => $counterparty->id, 'name' => $counterparty->name],
                'currencies' => [],
                'statement' => null,
                'filters' => ['currency' => null, 'mode' => StatementMode::Client->value, 'from' => null, 'to' => null],
                'modes' => $this->modes(),
                'bucketLabels' => $this->bucketLabels(),
            ]);
        }

        return Inertia::render('counterparties/statement', [
            'counterparty' => ['id' => $counterparty->id, 'name' => $counterparty->name],
            'currencies' => $currencies->map(fn (Currency $c): array => ['id' => $c->id, 'code' => $c->code])->values()->all(),
            'statement' => $this->present($statement),
            'filters' => [
                'currency' => $statement->currency->code,
                'mode' => $statement->mode->value,
                'from' => $statement->from?->toDateString(),
                'to' => $statement->to?->toDateString(),
            ],
            'modes' => $this->modes(),
            'bucketLabels' => $this->bucketLabels(),
        ]);
    }

    /**
     * The same statement, as a document.
     *
     * Built through the same path as the screen, from the same query string, so the
     * page somebody is looking at and the file they hand over cannot say different
     * things. In particular the mode is resolved once, in {@see resolve}: a client
     * copy on screen produces a client copy on paper.
     */
    public function pdf(Request $request, Counterparty $counterparty): HttpResponse
    {
        Gate::authorize('view', $counterparty);

        $statement = $this->resolve($request, $counterparty, $this->currenciesTraded($counterparty));

        if ($statement === null) {
            abort(404, __('statements.no_currencies'));
        }

        return response($this->pdf->render($statement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filenames->download($statement).'"',
        ]);
    }

    /**
     * The same statement, as a spreadsheet.
     *
     * Through the same {@see resolve} as the screen and the PDF, so all three agree on
     * the mode. In Client mode the profit column is absent from the file because the
     * figures were never queried, not because this method left them out.
     */
    public function csv(Request $request, Counterparty $counterparty, CsvWriter $writer): StreamedResponse
    {
        Gate::authorize('view', $counterparty);

        $statement = $this->resolve($request, $counterparty, $this->currenciesTraded($counterparty));

        if ($statement === null) {
            abort(404, __('statements.no_currencies'));
        }

        return $writer->response(new StatementExport($statement, $this->filenames));
    }

    /**
     * Read the filters and build the statement, or null when there is nothing to build.
     *
     * Shared by the screen and the document deliberately. Two copies of this would be
     * two chances for a client copy to become an internal one on the way to a file.
     *
     * @param  Collection<int, Currency>  $currencies
     */
    private function resolve(Request $request, Counterparty $counterparty, Collection $currencies): ?CounterpartyStatement
    {
        $validated = $request->validate([
            'currency' => ['nullable', 'string', Rule::in($currencies->pluck('code')->all())],
            'mode' => ['nullable', Rule::enum(StatementMode::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $currency = $currencies->firstWhere('code', $validated['currency'] ?? null)
            ?? $currencies->first();

        if (! $currency instanceof Currency) {
            return null;
        }

        return $this->statements->build(
            $counterparty,
            $currency,
            StatementMode::tryFrom($validated['mode'] ?? '') ?? StatementMode::Client,
            isset($validated['from']) ? Carbon::parse($validated['from']) : null,
            isset($validated['to']) ? Carbon::parse($validated['to']) : null,
        );
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
