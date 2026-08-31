<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Audit\Auditable;
use App\Domain\Money\Money;
use App\Enums\ReconciliationStatus;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A record of comparing what is held against what the ledger says is held.
 *
 * The figures are frozen once written — the database enforces it with a trigger, and
 * this model refuses to attempt it so the failure is a clear exception at the call
 * site rather than a SQL error surfacing from underneath. Only the explanation can
 * change afterwards; a recount is a new record.
 *
 * @property int $id
 * @property int $account_id
 * @property int $currency_id
 * @property Carbon $as_of
 * @property Money $counted_amount
 * @property Money $ledger_amount
 * @property Money $difference
 * @property ReconciliationStatus $status
 * @property string|null $note
 * @property string|null $resolution
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property int|null $adjustment_transaction_id
 * @property int|null $created_by
 */
final class Reconciliation extends Model
{
    use Auditable;
    use BelongsToBusiness;

    /** The columns a reconciliation exists to record. None may be edited. */
    private const array FROZEN = [
        'account_id',
        'currency_id',
        'as_of',
        'counted_amount',
        'ledger_amount',
        'difference',
    ];

    protected $fillable = [
        'account_id',
        'currency_id',
        'as_of',
        'counted_amount',
        'ledger_amount',
        'difference',
        'status',
        'note',
        'resolution',
        'resolved_by',
        'resolved_at',
        'adjustment_transaction_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'counted_amount' => MoneyCast::class.':currency_id',
            'ledger_amount' => MoneyCast::class.':currency_id',
            'difference' => MoneyCast::class.':currency_id',
            'status' => ReconciliationStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $reconciliation): void {
            $edited = array_intersect(array_keys($reconciliation->getDirty()), self::FROZEN);

            if ($edited !== []) {
                throw new RuntimeException(
                    'A reconciliation records what was found on a day: '.implode(', ', $edited)
                    .' cannot be edited. Record a new reconciliation, or post an adjustment to '
                    .'correct the ledger.'
                );
            }
        });
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * The adjustment posted to correct a real difference, if one was.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'adjustment_transaction_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isBalanced(): bool
    {
        return $this->status === ReconciliationStatus::Balanced;
    }

    /** Whether more was found than the ledger expected. */
    public function isSurplus(): bool
    {
        return $this->difference->isPositive();
    }
}
