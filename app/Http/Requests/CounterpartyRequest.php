<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Money\Decimal;
use App\Domain\Money\Money;
use App\Domain\Tenancy\Owned;
use App\Enums\CounterpartyType;
use App\Models\Counterparty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CounterpartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $counterparty = $this->route('counterparty');

        return $counterparty instanceof Counterparty
            ? Gate::allows('update', $counterparty)
            : Gate::allows('create', Counterparty::class);
    }

    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CounterpartyType::class)],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'preferred_currency_id' => ['nullable', 'integer', Owned::exists('currencies', 'id')],
            'is_active' => ['required', 'boolean'],

            'positions' => ['array'],
            'positions.*.currency_id' => ['required', 'integer', Owned::exists('currencies', 'id')],
            'positions.*.amount' => ['required', 'string'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array{currency_id?: int|string, amount?: string}> $rows */
            $rows = $this->input('positions', []);

            $seen = [];

            foreach ($rows as $index => $row) {
                $key = (string) ($row['currency_id'] ?? '');

                if (in_array($key, $seen, true)) {
                    $validator->errors()->add("positions.{$index}.amount", __('validation.distinct', [
                        'attribute' => __('counterparties.opening_positions'),
                    ]));
                }

                $seen[] = $key;

                $amount = $row['amount'] ?? '';

                if (! Decimal::isValid($amount)) {
                    $validator->errors()->add("positions.{$index}.amount", __('validation.numeric', [
                        'attribute' => __('counterparties.opening_positions'),
                    ]));

                    continue;
                }

                if (Decimal::scaleOf($amount) > Money::SCALE) {
                    $validator->errors()->add("positions.{$index}.amount", __('validation.max.numeric', [
                        'attribute' => __('counterparties.opening_positions'),
                        'max' => Money::SCALE,
                    ]));
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        foreach (['preferred_currency_id', 'country', 'phone', 'email'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }

        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper($this->input('country'))]);
        }
    }
}
