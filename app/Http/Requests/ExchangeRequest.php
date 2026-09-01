<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Exchange\ExchangeInput;
use App\Domain\Money\Decimal;
use App\Domain\Money\Money;
use App\Domain\Tenancy\Owned;
use App\Enums\MarginBasis;
use App\Enums\MovementMethod;
use App\Enums\ProfitMethod;
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

            'received_currency_id' => ['required', 'integer', Owned::exists('currencies', 'id')],
            'received_amount' => ['required', 'string'],
            'received_into_id' => ['required', 'integer', Owned::exists('accounts', 'id')->whereNull('deleted_at')],

            'delivered_currency_id' => ['required', 'integer', 'different:received_currency_id', Owned::exists('currencies', 'id')],
            'delivered_amount' => ['required', 'string'],
            'delivered_from_id' => ['required', 'integer', Owned::exists('accounts', 'id')->whereNull('deleted_at')],

            'profit_method' => ['required', Rule::enum(ProfitMethod::class)],
            'margin_basis' => ['nullable', Rule::enum(MarginBasis::class)],
            'cost_rate' => ['nullable', 'string'],
            'profit_value' => ['nullable', 'string'],

            'fees_charged' => ['nullable', 'string'],
            'expenses' => ['nullable', 'string'],
            'commissions' => ['nullable', 'string'],

            'counterparty_id' => ['nullable', 'integer', Owned::exists('counterparties', 'id')->whereNull('deleted_at')],
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
            foreach (['received_amount', 'delivered_amount', 'cost_rate', 'profit_value', 'fees_charged', 'expenses', 'commissions'] as $field) {
                $value = $this->input($field);

                if ($value === null || $value === '') {
                    continue;
                }

                if (! is_string($value) || ! Decimal::isValid($value)) {
                    $validator->errors()->add($field, __('validation.numeric', ['attribute' => $field]));

                    continue;
                }

                // Rates carry more precision than amounts, so they are allowed more.
                $limit = in_array($field, ['cost_rate', 'profit_value'], true) ? 12 : Money::SCALE;

                if (Decimal::scaleOf($value) > $limit) {
                    $validator->errors()->add($field, __('validation.max.numeric', [
                        'attribute' => $field,
                        'max' => $limit,
                    ]));
                }
            }

            $this->checkTheLegsAreRealAmounts($validator);
            $this->checkTheMethodHasWhatItNeeds($validator);
        });
    }

    /**
     * Both legs, and what is taken off the deal.
     *
     * A leg of zero has no rate — the calculator divides by it — and a negative leg is
     * the same deal the other way round under a name that hides it. Neither was
     * refused, and a zero reached the calculator as a DomainException.
     *
     * Fees, expenses and commissions are allowed to be zero, because a deal with no fee
     * is ordinary. Negative is not: a negative fee is a fee going the other way, which
     * is a different thing to record.
     */
    private function checkTheLegsAreRealAmounts(Validator $validator): void
    {
        // Field, the label the operator sees, and whether zero is allowed. The label is
        // mapped rather than derived: `fees_charged` is called `fees` on the screen, and
        // deriving it would print a raw translation key at somebody.
        $fields = [
            ['received_amount', 'received', false],
            ['delivered_amount', 'delivered', false],
            ['fees_charged', 'fees', true],
            ['expenses', 'expenses', true],
            ['commissions', 'commissions', true],
        ];

        foreach ($fields as [$field, $label, $zeroAllowed]) {
            $value = $this->input($field);

            if (! is_string($value) || ! Decimal::isValid($value)) {
                continue;
            }

            $comparison = bccomp($value, '0', Decimal::WORKING_SCALE);
            $attribute = __('transactions.exchange.'.$label);

            if (! $zeroAllowed && $comparison <= 0) {
                $validator->errors()->add($field, __('transactions.exchange.must_be_positive', ['attribute' => $attribute]));
            }

            if ($zeroAllowed && $comparison < 0) {
                $validator->errors()->add($field, __('transactions.exchange.cannot_be_negative', ['attribute' => $attribute]));
            }
        }
    }

    /**
     * A margin method with nothing to work from.
     *
     * Both of these fields are optional in the rules above, because which of them is
     * needed depends on the method chosen — and the enum already knows. Left unasked,
     * a rate-difference deal with an empty cost rate passed validation and threw a
     * DomainException out of the calculator: a stack trace where a field error belongs,
     * on the screen where the day's money is recorded.
     */
    private function checkTheMethodHasWhatItNeeds(Validator $validator): void
    {
        $method = ProfitMethod::tryFrom((string) $this->input('profit_method'));

        if (! $method instanceof ProfitMethod) {
            return;
        }

        if ($method->needsCostRate()) {
            $this->requirePositive($validator, 'cost_rate', __('transactions.exchange.cost_rate'));
        }

        if ($method->needsValue()) {
            $this->requirePositive($validator, 'profit_value', $method->valueLabel());
        }
    }

    /**
     * Present, and greater than zero.
     *
     * Zero is refused as well as absent. A cost rate of nothing is not a cheap deal,
     * it is a missing figure, and the margin worked out from it would be the whole of
     * what the customer paid.
     */
    private function requirePositive(Validator $validator, string $field, string $label): void
    {
        $value = $this->input($field);

        if ($value === null || $value === '') {
            $validator->errors()->add($field, __('validation.required', ['attribute' => $label]));

            return;
        }

        // Anything that is not a decimal has already been reported above.
        if (is_string($value) && Decimal::isValid($value) && bccomp($value, '0', Decimal::WORKING_SCALE) <= 0) {
            $validator->errors()->add($field, __('transactions.exchange.must_be_positive', ['attribute' => $label]));
        }
    }

    protected function prepareForValidation(): void
    {
        foreach (['cost_rate', 'profit_value', 'fees_charged', 'expenses', 'commissions', 'counterparty_id', 'method'] as $field) {
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

        // Fees, expenses and commissions are denominated in whichever currency the
        // margin is, because they are added to and taken off it. Building them against
        // the received leg regardless would hand the calculator a fee in the wrong
        // currency the moment the margin sat on the other side.
        $basis = $this->validated('margin_basis') !== null
            ? MarginBasis::from((string) $this->validated('margin_basis'))
            : MarginBasis::Received;

        $marginCurrency = $basis === MarginBasis::Received ? $received : $delivered;

        return new ExchangeInput(
            receivedCurrency: $received,
            receivedAmount: $received->money((string) $this->validated('received_amount')),
            receivedInto: Account::query()->findOrFail((int) $this->validated('received_into_id')),
            deliveredCurrency: $delivered,
            deliveredAmount: $delivered->money((string) $this->validated('delivered_amount')),
            deliveredFrom: Account::query()->findOrFail((int) $this->validated('delivered_from_id')),
            occurredAt: new \DateTimeImmutable((string) $this->validated('occurred_at')),
            profitMethod: ProfitMethod::from((string) $this->validated('profit_method')),
            // Absent means the received leg, which is what every deal recorded before
            // the basis existed meant. See MarginBasis.
            marginBasis: $basis,
            costRate: $this->validated('cost_rate'),
            profitValue: $this->validated('profit_value'),
            feesCharged: $money('fees_charged', $marginCurrency),
            expenses: $money('expenses', $marginCurrency),
            commissions: $money('commissions', $marginCurrency),
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
