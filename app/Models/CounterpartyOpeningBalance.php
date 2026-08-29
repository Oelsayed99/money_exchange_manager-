<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a party stood before the books began: one signed figure per currency.
 *
 * Positive means they owed us; negative means we were holding theirs.
 *
 * @property int $id
 * @property int $counterparty_id
 * @property int $currency_id
 * @property Money|null $amount
 * @property Money|null $posted_amount how much of it has reached the ledger
 */
final class CounterpartyOpeningBalance extends Model
{
    protected $table = 'counterparty_opening_balances';

    protected $fillable = [
        'counterparty_id',
        'currency_id',
        'amount',
        'posted_amount',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class.':currency_id',
            'posted_amount' => MoneyCast::class.':currency_id',
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
