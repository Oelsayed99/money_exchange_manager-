<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Money\CurrencySpec;
use App\Models\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validation for creating and updating a currency.
 *
 * One request class serves both, because the rules differ only in whether the
 * uniqueness check ignores the record being edited.
 */
final class CurrencyRequest extends FormRequest
{
    /**
     * Authorization runs here rather than in the controller so that a request without
     * the permission is rejected before any validation, and therefore before any hint
     * about what the form expects leaks back to the caller.
     */
    public function authorize(): bool
    {
        $currency = $this->route('currency');

        return $currency instanceof Currency
            ? Gate::allows('update', $currency)
            : Gate::allows('create', Currency::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $currency = $this->route('currency');
        $ignoreId = $currency instanceof Currency ? $currency->getKey() : null;

        return [
            'code' => [
                'required',
                'string',
                'max:12',
                // Letters only: a code is an identifier, not a label. Length is capped
                // at 12 rather than 3 so future non-ISO codes remain possible.
                'regex:/^[A-Z]{2,12}$/',
                Rule::unique('currencies', 'code')->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'symbol' => ['nullable', 'string', 'max:16'],
            'decimal_places' => ['required', 'integer', 'between:0,'.CurrencySpec::MAX_DECIMAL_PLACES],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:0,65535'],
        ];
    }

    /**
     * Normalise the code before validation so that uniqueness is checked against the
     * value that will actually be stored. Without this, 'usd' would pass a uniqueness
     * check against an existing 'USD' and then collide at the database.
     */
    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        if (is_string($code)) {
            $this->merge(['code' => strtoupper(trim($code))]);
        }
    }
}
