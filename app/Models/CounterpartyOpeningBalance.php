<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Money\Money;
use App\Enums\BalanceBucket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One declared opening position: a party, a bucket, a currency, an amount.
 *
 * @property int $id
 * @property int $counterparty_id
 * @property int $currency_id
 * @property BalanceBucket $bucket
 * @property Money|null $amount
 */
final class CounterpartyOpeningBalance extends Model
{
    protected $table = 'counterparty_opening_balances';

    protected $fillable = [
        'counterparty_id',
        'currency_id',
        'bucket',
        'amount',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bucket' => BalanceBucket::class,
            'amount' => MoneyCast::class.':currency_id',
        ];
    }

    /** @return BelongsTo<Counterparty, $this> */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
