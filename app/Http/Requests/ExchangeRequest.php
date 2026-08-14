<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Exchange\ExchangeInput;
use App\Domain\Money\Decimal;
use App\Domain\Money\Money;
use App\Enums\MovementMethod;
use App\Enums\ProfitMethod;
use App\Enums\SpreadType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for recording a currency exchange.
 *
 * Every monetary value arrives as a decimal string and is validated as one. Nothing is
 * cast to a float on the way in, because a float is the one thing that could quietly
 * change a number between the browser and the ledger.
 */
final class ExchangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Transaction::class);
    }

    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        return [
            'occurred_at' => ['required', 'date'],

            'received_currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')],
            'received_amount' => ['required', 'string'],
            'received_into_id' => ['required', 'integer', Rule::exists('accounts', 'id')->whereNull('deleted_at')],

            'delivered_currency_id' => ['required', 'integer', 'different:received_currency_id', Rule::exists('currencies', 'id')],
            'delivered_amount' => ['required', 'string'],
            'delivered_from_id' => ['required', 'integer', Rule::exists('accounts', 'id')->whereNull('deleted_at')],

            'profit_method' => ['required', Rule::enum(ProfitMethod::class)],
            'cost_rate' => ['nullable', 'string'],
            'spread_type' => ['nullable', Rule::enum(SpreadType::class)],
            'spread_value' => ['nullable', 'string'],

            'fees_charged' => ['nullable', 'string'],
            'expenses' => ['nullable', 'string'],
            'commissions' => ['nullable', 'string'],

            'counterparty_id' => ['nullable', 'integer', Rule::exists('counterparties', 'id')->whereNull('deleted_at')],
            'method' => ['nullable', Rule::enum(MovementMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],

            // Section 3 requires a warning before saving a loss. Enforced here rather
            // than only in the interface: a warning a caller can skip is not a warning.
            'confirm_loss' => ['nullable', 'boolean'],

            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['received_amount', 'delivered_amount', 'cost_rate', 'spread_value', 'fees_charged', 'expenses', 'commissions'] as $field) {
                $value = $this->input($field);

                if ($value === null || $value === '') {
                    continue;
                }

                if (! is_string($value) || ! Decimal::isValid($value)) {
                    $validator->errors()->add($field, __('validation.numeric', ['attribute' => $field]));

                    continue;
                }

                // Rates carry more precision than amounts, so they are allowed more.
                $limit = in_array($field, ['cost_rate', 'spread_value'], true) ? 12 : Money::SCALE;

                if (Decimal::scaleOf($value) > $limit) {
                    $validator->errors()->add($field, __('validation.max.numeric', [
                        'attribute' => $field,
                        'max' => $limit,
                    ]));
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        foreach (['cost_rate', 'spread_value', 'fees_charged', 'expenses', 'commissions', 'counterparty_id', 'method', 'spread_type'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * Turn the validated request into the domain input.
     *
     * Shared by the preview and by recording, so the figures shown and the figures
     * stored are built from the same request in the same way.
     */
    public function toExchangeInput(): ExchangeInput
    {
        $received = Currency::query()->findOrFail((int) $this->validated('received_currency_id'));
        $delivered = Currency::query()->findOrFail((int) $this->validated('delivered_currency_id'));

        $money = function (string $field, Currency $currency): ?Money {
            $value = $this->validated($field);

            return is_string($value) && $value !== '' ? $currency->money($value) : null;
        };

        return new ExchangeInput(
            receivedCurrency: $received,
            receivedAmount: $received->money((string) $this->validated('received_amount')),
            receivedInto: Account::query()->findOrFail((int) $this->validated('received_into_id')),
            deliveredCurrency: $delivered,
            deliveredAmount: $delivered->money((string) $this->validated('delivered_amount')),
            deliveredFrom: Account::query()->findOrFail((int) $this->validated('delivered_from_id')),
            occurredAt: new \DateTimeImmutable((string) $this->validated('occurred_at')),
            profitMethod: ProfitMethod::from((string) $this->validated('profit_method')),
            costRate: $this->validated('cost_rate'),
            spreadType: $this->validated('spread_type') !== null
                ? SpreadType::from((string) $this->validated('spread_type'))
                : null,
            spreadValue: $this->validated('spread_value'),
            feesCharged: $money('fees_charged', $received),
            expenses: $money('expenses', $received),
            commissions: $money('commissions', $received),
            counterparty: $this->validated('counterparty_id') !== null
                ? Counterparty::query()->find((int) $this->validated('counterparty_id'))
                : null,
            method: $this->validated('method') !== null
                ? MovementMethod::from((string) $this->validated('method'))
                : null,
            reference: $this->validated('reference'),
            description: $this->validated('description'),
            idempotencyKey: $this->validated('idempotency_key'),
        );
    }

    public function lossConfirmed(): bool
    {
        return (bool) $this->validated('confirm_loss', false);
    }
}
