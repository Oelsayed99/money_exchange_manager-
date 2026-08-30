<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Money\Money;
use App\Enums\EntryDirection;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One side of one posting. The atomic fact of the ledger.
 *
 * Append-only. The database rejects updates and deletes outright; this model refuses
 * to attempt them so the failure is a clear exception at the call site rather than a
 * SQL error surfacing from a trigger.
 *
 * @property int $id
 * @property int $transaction_id
 * @property int $ledger_account_id
 * @property int $currency_id
 * @property EntryDirection $direction
 * @property Money $amount
 * @property int $sequence
 * @property Carbon $occurred_at
 */
final class LedgerEntry extends Model
{
    use BelongsToBusiness;

    public const UPDATED_AT = null;

    protected $fillable = [
        'transaction_id',
        'ledger_account_id',
        'currency_id',
        'direction',
        'amount',
        'sequence',
        'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => EntryDirection::class,
            'amount' => MoneyCast::class.':currency_id',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    /**
     * The ledger account this entry belongs to, guaranteed present.
     *
     * The foreign key is non-null and restricts deletion, so a null here means the
     * ledger itself is corrupt — worth a loud failure rather than a silent one.
     */
    public function account(): LedgerAccount
    {
        $account = $this->ledgerAccount;

        if (! $account instanceof LedgerAccount) {
            throw new RuntimeException(
                "Ledger entry #{$this->id} has no ledger account. The ledger is corrupt."
            );
        }

        return $account;
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('Ledger entries are append-only: correct a mistake with a reversal, never an edit.');
        });

        self::deleting(function (): never {
            throw new RuntimeException('Ledger entries are append-only and cannot be deleted.');
        });
    }
}
