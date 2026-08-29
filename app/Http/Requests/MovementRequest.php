<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Ledger\TransactionInput;
use App\Domain\Money\Decimal;
use App\Domain\Money\Money;
use App\Enums\MovementMethod;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Recording an ordinary movement: a deposit, a loan, a settlement, a transfer.
 *
 * Everything the ledger can post except a currency exchange, which needs two amounts
 * and a rate and has its own screen.
 *
 * What each type requires is read from the type itself rather than listed here again,
 * so adding a case to the enum does not mean remembering to update a validator.
 */
final class MovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Transaction::class);
    }

    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        $type = $this->movementType();

        return [
            'type' => [
                'required',
                Rule::enum(TransactionType::class)->only(
                    array_filter(TransactionType::cases(), fn (TransactionType $t): bool => $t->recordableByHand()),
                ),
            ],
            'occurred_at' => ['required', 'date'],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')],
            'amount' => ['required', 'string'],
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->whereNull('deleted_at')],

            'destination_account_id' => [
                $type?->needsDestinationAccount() === true ? 'required' : 'nullable',
                'integer',
                'different:account_id',
                Rule::exists('accounts', 'id')->whereNull('deleted_at'),
            ],

            'counterparty_id' => [
                $type?->needsCounterparty() === true ? 'required' : 'nullable',
                'integer',
                Rule::exists('counterparties', 'id')->whereNull('deleted_at'),
            ],

            // The money that actually changed hands, when it was not the currency the
            // movement is being recorded in. Both or neither.
            'cash_currency_id' => [
                $type?->mayConvert() === true ? 'nullable' : 'prohibited',
                'integer',
                Rule::exists('currencies', 'id'),
            ],
            'cash_amount' => ['nullable', 'required_with:cash_currency_id', 'string'],
            'rate' => ['nullable', 'required_with:cash_currency_id', 'string'],

            'method' => ['nullable', Rule::enum(MovementMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['destination_account_id', 'counterparty_id', 'bucket', 'method'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $amount = $this->input('amount');

            if (! is_string($amount) || ! Decimal::isValid($amount)) {
                $validator->errors()->add('amount', __('validation.numeric', ['attribute' => __('movements.amount')]));

                return;
            }

            if (Decimal::scaleOf($amount) > Money::SCALE) {
                $validator->errors()->add('amount', __('validation.max.numeric', [
                    'attribute' => __('movements.amount'),
                    'max' => Money::SCALE,
                ]));
            }

            // A movement of nothing is not a movement, and a negative one is the same
            // movement in the other direction under a name that hides it.
            if (bccomp($amount, '0', Decimal::WORKING_SCALE) <= 0) {
                $validator->errors()->add('amount', __('movements.amount_positive'));
            }
        });
    }

    public function movementType(): ?TransactionType
    {
        $type = $this->input('type');

        return is_string($type) ? TransactionType::tryFrom($type) : null;
    }

    /** Turn the validated request into the domain input the posting rules take. */
    public function toTransactionInput(): TransactionInput
    {
        $currency = Currency::query()->findOrFail((int) $this->validated('currency_id'));
        $type = TransactionType::from((string) $this->validated('type'));

        $cashCurrency = $this->validated('cash_currency_id') !== null
            ? Currency::query()->find((int) $this->validated('cash_currency_id'))
            : null;

        return new TransactionInput(
            type: $type,
            currency: $currency,
            amount: $currency->money((string) $this->validated('amount')),
            occurredAt: new \DateTimeImmutable((string) $this->validated('occurred_at')),
            account: Account::query()->findOrFail((int) $this->validated('account_id')),
            destinationAccount: $this->validated('destination_account_id') !== null
                ? Account::query()->find((int) $this->validated('destination_account_id'))
                : null,
            counterparty: $this->validated('counterparty_id') !== null
                ? Counterparty::query()->find((int) $this->validated('counterparty_id'))
                : null,
            cashCurrency: $cashCurrency,
            cashAmount: $cashCurrency instanceof Currency && $this->validated('cash_amount') !== null
                ? $cashCurrency->money((string) $this->validated('cash_amount'))
                : null,
            rate: $this->validated('rate'),
            method: $this->validated('method') !== null
                ? MovementMethod::from((string) $this->validated('method'))
                : null,
            reference: $this->validated('reference'),
            description: $this->validated('description'),
            idempotencyKey: $this->validated('idempotency_key'),
        );
    }
}
