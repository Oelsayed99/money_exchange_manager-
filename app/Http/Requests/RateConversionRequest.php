<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Exchange\RateQuote;
use App\Domain\Money\Decimal;
use App\Domain\Money\Money;
use App\Domain\Tenancy\Owned;
use App\Models\Currency;
use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Solving a rate against two amounts, for the live exchange form.
 *
 * A rate and two amounts are three facts with only two degrees of freedom: give any
 * two and the third follows. The form lets the operator type whichever two they know,
 * and this asks for exactly two — not one, which would be unsolvable, and not three,
 * which would let the caller assert a rate the amounts contradict.
 *
 * Nothing here records anything. It is arithmetic in service of a form field.
 */
final class RateConversionRequest extends FormRequest
{
    /** The three quantities, of which exactly two must be supplied. */
    private const array SOLVABLE = ['rate', 'base_amount', 'quote_amount'];

    public function authorize(): bool
    {
        // Working out what a deal would come to is part of preparing one, so this
        // follows the preview: recording is a separate permission from posting.
        return Gate::allows('create', Transaction::class);
    }

    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        return [
            'base_currency_id' => ['required', 'integer', Owned::exists('currencies', 'id')],
            'quote_currency_id' => ['required', 'integer', 'different:base_currency_id', Owned::exists('currencies', 'id')],
            'rate' => ['nullable', 'string'],
            'base_amount' => ['nullable', 'string'],
            'quote_amount' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (self::SOLVABLE as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $supplied = [];

            foreach (self::SOLVABLE as $field) {
                $value = $this->input($field);

                if ($value === null) {
                    continue;
                }

                $supplied[] = $field;

                if (! is_string($value) || ! Decimal::isValid($value)) {
                    $validator->errors()->add($field, __('validation.numeric', ['attribute' => $field]));

                    continue;
                }

                $limit = $field === 'rate' ? RateQuote::SCALE : Money::SCALE;

                if (Decimal::scaleOf($value) > $limit) {
                    $validator->errors()->add($field, __('validation.max.numeric', [
                        'attribute' => $field,
                        'max' => $limit,
                    ]));
                }
            }

            if (count($supplied) !== 2) {
                $validator->errors()->add('rate', __('transactions.exchange.solve_needs_two'));

                return;
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Caught here rather than left to RateQuote so the operator gets a message
            // against the field they typed in, not a five hundred.
            $rate = $this->input('rate');

            if (is_string($rate) && Decimal::isValid($rate) && bccomp($rate, '0', Decimal::WORKING_SCALE) <= 0) {
                $validator->errors()->add('rate', __('transactions.exchange.rate_positive'));
            }

            // Every rate divides zero into zero, so a rate cannot be recovered from it.
            $base = $this->input('base_amount');

            if (! in_array('rate', $supplied, true) && is_string($base) && Decimal::isValid($base)
                && bccomp($base, '0', Decimal::WORKING_SCALE) === 0) {
                $validator->errors()->add('base_amount', __('transactions.exchange.rate_needs_amount'));
            }
        });
    }

    public function baseCurrency(): Currency
    {
        return Currency::query()->findOrFail((int) $this->validated('base_currency_id'));
    }

    public function quoteCurrency(): Currency
    {
        return Currency::query()->findOrFail((int) $this->validated('quote_currency_id'));
    }

    /** The quantity left blank, and so the one being solved for. */
    public function solvingFor(): string
    {
        foreach (self::SOLVABLE as $field) {
            if ($this->validated($field) === null) {
                return $field;
            }
        }

        // Unreachable: validation has already established that exactly one is absent.
        throw new \LogicException('Every quantity was supplied; there is nothing to solve for.');
    }

    /** @return numeric-string */
    public function decimal(string $field): string
    {
        $value = (string) $this->validated($field);

        Decimal::assertValid($value);

        return $value;
    }
}
