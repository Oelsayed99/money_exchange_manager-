<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Http\Requests\AccountRequest;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Custody locations.
 *
 * No destroy action: an account is referenced by ledger history that must stay
 * reproducible (Section 7). Accounts are deactivated instead.
 */
final class AccountController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Account::class);

        $accounts = Account::query()
            ->with(['counterparty', 'currencies'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account): array => $this->present($account))
            ->all();

        return Inertia::render('accounts/index', ['accounts' => $accounts]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Account::class);

        return Inertia::render('accounts/form', [
            'account' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(AccountRequest $request): RedirectResponse
    {
        $account = DB::transaction(function () use ($request): Account {
            $account = Account::query()->create($request->safe()->except('currencies'));

            $this->syncCurrencies($account, $request->validated('currencies', []));

            return $account;
        });

        return to_route('accounts.index')->with('success', __('accounts.created', ['name' => $account->name]));
    }

    public function edit(Account $account): Response
    {
        Gate::authorize('update', $account);

        $account->load(['counterparty', 'currencies']);

        return Inertia::render('accounts/form', [
            'account' => $this->present($account),
            ...$this->formOptions(),
        ]);
    }

    public function update(AccountRequest $request, Account $account): RedirectResponse
    {
        DB::transaction(function () use ($request, $account): void {
            $account->update($request->safe()->except('currencies'));

            $this->syncCurrencies($account, $request->validated('currencies', []));
        });

        return to_route('accounts.index')->with('success', __('accounts.updated'));
    }

    /**
     * @param  array<int, array{currency_id: int|string, opening_balance: string}>  $rows
     */
    private function syncCurrencies(Account $account, array $rows): void
    {
        $pivot = [];

        foreach ($rows as $row) {
            // A plain decimal string, not a Money: Eloquent casts pivot attributes
            // before the foreign keys are merged, so the currency cannot be verified
            // there. A bare number asserts no currency, so nothing can contradict it.
            $pivot[(int) $row['currency_id']] = ['opening_balance' => $row['opening_balance']];
        }

        // sync, not syncWithoutDetaching: unticking a currency removes it. Guarding
        // against removing one that already carries ledger history belongs with the
        // ledger, in Phase 3.
        $account->currencies()->sync($pivot);
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'accountTypes' => array_map(
                fn (AccountType $type): array => [
                    'value' => $type->value,
                    'label' => __('accounts.types.'.$type->value),
                    'isLiability' => $type->isLiability(),
                ],
                AccountType::cases(),
            ),
            'availableCurrencies' => Currency::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'code', 'decimal_places'])
                ->map(fn (Currency $currency): array => [
                    'id' => $currency->id,
                    'code' => $currency->code,
                    'decimal_places' => $currency->decimal_places,
                ])
                ->all(),
            'counterparties' => Counterparty::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Counterparty $party): array => ['id' => $party->id, 'name' => $party->name])
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(Account $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type->value,
            'type_label' => __('accounts.types.'.$account->type->value),
            'is_liability' => $account->type->isLiability(),
            'counterparty_id' => $account->counterparty_id,
            'counterparty_name' => $account->counterparty?->name,
            'owner' => $account->owner,
            'provider' => $account->provider,
            // Never the raw identifier: masked for the screen, and the audit trail
            // records only that it changed.
            'masked_identifier' => $account->masked_identifier,
            'is_active' => $account->is_active,
            'color' => $account->color,
            'icon' => $account->icon,
            'sort_order' => $account->sort_order,
            'currencies' => $account->currencies
                ->map(fn (Currency $currency): array => [
                    'currency_id' => $currency->id,
                    'code' => $currency->code,
                    // Money crosses the wire as a string, never a JSON number (R1).
                    'opening_balance' => $account->openingBalance($currency)?->toDisplayString() ?? '0',
                ])
                ->values()
                ->all(),
        ];
    }
}
