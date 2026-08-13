<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Money\Money;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A currency an account holds, with its declared opening balance.
 *
 * A pivot model rather than a bare pivot table so the opening balance can be cast to
 * Money. An amount without its currency is a number that means nothing, and the
 * currency is right here on the row.
 *
 * @property int $id
 * @property int $account_id
 * @property int $currency_id
 * @property Money|null $opening_balance
 */
final class AccountCurrency extends Pivot
{
    protected $table = 'account_currency';

    public $incrementing = true;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'opening_balance' => MoneyCast::class.':currency_id',
        ];
    }
}
