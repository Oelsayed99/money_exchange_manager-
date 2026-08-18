<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Money\Money;
use App\Domain\Reporting\ClientTotal;
use App\Domain\Reporting\CounterpartyPosition;
use App\Domain\Reporting\Dashboard;
use App\Domain\Reporting\DashboardFilters;
use App\Domain\Reporting\DashboardQuery;
use App\Enums\BalanceBucket;
use App\Enums\CounterpartyStatus;
use App\Models\Counterparty;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The overall picture: what is held, what is owed either way, and what was earned.
 *
 * Every figure is per currency and none is added across them. There is no base
 * currency, so a combined headline would need a rate — and would then move when the
 * market did, for reasons having nothing to do with the business.
 */
final class DashboardController extends Controller
{
    public function __construct(private readonly DashboardQuery $dashboard) {}

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'counterparty' => ['nullable', 'integer', Rule::exists('counterparties', 'id')->whereNull('deleted_at')],
            'currency' => ['nullable', 'string', Rule::exists('currencies', 'code')],
            'status' => ['nullable', Rule::enum(CounterpartyStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $filters = new DashboardFilters(
            counterparty: isset($validated['counterparty'])
                ? Counterparty::query()->find((int) $validated['counterparty'])
                : null,
            currency: isset($validated['currency'])
                ? Currency::query()->where('code', $validated['currency'])->first()
                : null,
            from: isset($validated['from']) ? Carbon::parse($validated['from']) : null,
            to: isset($validated['to']) ? Carbon::parse($validated['to']) : null,
            status: isset($validated['status']) ? CounterpartyStatus::from($validated['status']) : null,
        );

        return Inertia::render('dashboard', [
            'dashboard' => $this->present($this->dashboard->run($filters)),
            'filters' => [
                'counterparty' => $filters->counterparty?->getKey(),
                'currency' => $filters->currency?->code,
                'status' => $filters->status?->value,
                'from' => $filters->from?->toDateString(),
                'to' => $filters->to?->toDateString(),
            ],
            'options' => [
                'counterparties' => Counterparty::query()->where('is_active', true)->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Counterparty $c): array => ['id' => $c->id, 'name' => $c->name])
                    ->all(),
                'currencies' => Currency::query()->where('is_active', true)->orderBy('sort_order')
                    ->get(['id', 'code'])
                    ->map(fn (Currency $c): array => ['code' => $c->code])
                    ->all(),
                'statuses' => array_map(
                    fn (CounterpartyStatus $s): array => [
                        'value' => $s->value,
                        'label' => __('dashboard.statuses.'.$s->value),
                    ],
                    CounterpartyStatus::cases(),
                ),
                'buckets' => array_map(
                    fn (BalanceBucket $b): array => [
                        'value' => $b->value,
                        'label' => __('counterparties.buckets.'.$b->value),
                    ],
                    BalanceBucket::cases(),
                ),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Dashboard $dashboard): array
    {
        return [
            'currencies' => $dashboard->currencies,
            'cash_on_hand' => $this->amounts($dashboard->cashOnHand),
            'owed_to_us' => $this->amounts($dashboard->owedToUs),
            'owed_to_them' => $this->amounts($dashboard->owedToThem),
            'received' => $this->amounts($dashboard->receivedFromParties),
            'delivered' => $this->amounts($dashboard->deliveredToParties),
            'profit' => $this->amounts($dashboard->profit),
            'monthly_profit' => $dashboard->monthlyProfit,
            'monthly_flow' => $dashboard->monthlyFlow,
            'status_counts' => $dashboard->statusCounts,
            'top_clients' => array_map(
                fn (ClientTotal $client): array => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'owed_to_us' => $client->owedToUs->jsonSerialize(),
                    'owed_to_them' => $client->owedToThem->jsonSerialize(),
                ],
                $dashboard->topClients,
            ),
            'counterparties' => array_map(
                fn (CounterpartyPosition $party): array => [
                    'id' => $party->id,
                    'name' => $party->name,
                    'status' => $party->status->value,
                    'status_by_currency' => array_map(
                        fn (CounterpartyStatus $s): string => $s->value,
                        $party->statusByCurrency,
                    ),
                    'positions' => array_map(
                        fn (array $buckets): array => $this->amounts($buckets),
                        $party->positions,
                    ),
                ],
                $dashboard->counterparties,
            ),
        ];
    }

    /**
     * @param  array<string, Money>  $amounts
     * @return array<string, array{amount: string, currency: string}>
     */
    private function amounts(array $amounts): array
    {
        // Strings, as everywhere else. A JSON number is a float64 in the browser (R1).
        return array_map(fn (Money $money): array => $money->jsonSerialize(), $amounts);
    }
}
