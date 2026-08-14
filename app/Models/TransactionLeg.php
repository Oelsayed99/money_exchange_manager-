<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Money\Money;
use App\Enums\LegRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A described flow within a transaction: what entered or left, and between whom.
 *
 * Section 2 requires an exchange to carry at least two legs rather than a single
 * amount. Legs are what a statement shows; ledger entries are the accounting. They are
 * separate because one leg can produce several entries — a delivered leg produces both
 * a cash credit and a clearing debit.
 *
 * @property int $id
 * @property int $transaction_id
 * @property LegRole $role
 * @property int $currency_id
 * @property Money $amount
 * @property int|null $account_id
 * @property int|null $counterparty_id
 * @property int $sequence
 */
final class TransactionLeg extends Model
{
    protected $fillable = [
        'transaction_id',
        'role',
        'currency_id',
        'amount',
        'account_id',
        'counterparty_id',
        'sequence',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => LegRole::class,
            'amount' => MoneyCast::class.':currency_id',
        ];
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Counterparty, $this> */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }
}
