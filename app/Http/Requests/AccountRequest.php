<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Money\Decimal;
use App\Domain\Money\Money;
use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account
            ? Gate::allows('update', $account)
            : Gate::allows('create', Account::class);
    }

    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'counterparty_id' => ['nullable', 'integer', Rule::exists('counterparties', 'id')->whereNull('deleted_at')],
            'owner' => ['nullable', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:255'],
            'identifier' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:0,65535'],
            'color' => ['nullable', 'string', 'max:20'],
            'icon' => ['nullable', 'string', 'max:50'],

            'currencies' => ['array'],
            'currencies.*.currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')],

            // A plain decimal string, never a float: the amount crosses the wire as
            // text precisely so nothing can round it on the way in.
            'currencies.*.opening_balance' => ['required', 'string'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array{currency_id?: int|string, opening_balance?: string}> $rows */
            $rows = $this->input('currencies', []);

            $seen = [];

            foreach ($rows as $index => $row) {
                $currencyId = $row['currency_id'] ?? null;

                // One row per currency, or an account would carry two contradictory
                // opening balances for the same thing.
                if ($currencyId !== null) {
                    if (in_array($currencyId, $seen, true)) {
                        $validator->errors()->add("currencies.{$index}.currency_id", __('validation.distinct', [
                            'attribute' => __('accounts.fields.currencies'),
                        ]));
                    }

                    $seen[] = $currencyId;
                }

                $amount = $row['opening_balance'] ?? '';

                if (! Decimal::isValid($amount)) {
                    $validator->errors()->add("currencies.{$index}.opening_balance", __('validation.numeric', [
                        'attribute' => __('accounts.fields.opening_balance'),
                    ]));

                    continue;
                }

                if (Decimal::scaleOf($amount) > Money::SCALE) {
                    $validator->errors()->add("currencies.{$index}.opening_balance", __('validation.max.numeric', [
                        'attribute' => __('accounts.fields.opening_balance'),
                        'max' => Money::SCALE,
                    ]));
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        // An empty string from a blank form field means "nothing", not zero-length text.
        if ($this->input('counterparty_id') === '') {
            $this->merge(['counterparty_id' => null]);
        }
    }
}
