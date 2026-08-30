<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Enums\MarginBasis;
use App\Enums\MovementMethod;
use App\Enums\ProfitMethod;
use App\Enums\ProfitStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A financial event, and the container for the entries it produced.
 *
 * Never created directly — everything goes through PostingService, which is the only
 * thing that may write ledger entries. Section 7: "Do not implement transaction
 * screens that directly increment or decrement balance columns without going through
 * the posting service."
 *
 * @property int $id
 * @property TransactionType $type
 * @property TransactionStatus $status
 * @property Carbon $occurred_at
 * @property int|null $counterparty_id
 * @property MovementMethod|null $method
 * @property ProfitMethod|null $profit_method
 * @property MarginBasis|null $margin_basis
 * @property ProfitStatus|null $profit_status
 * @property int|null $profit_currency_id
 * @property string|null $customer_rate
 * @property string|null $cost_rate
 * @property string|null $gross_profit
 * @property string|null $net_profit
 * @property string|null $reference
 * @property string|null $description
 * @property array<string, mixed>|null $draft_payload
 * @property string|null $idempotency_key
 * @property int|null $reversal_of_transaction_id
 * @property int|null $created_by
 * @property int|null $posted_by
 * @property Carbon|null $posted_at
 */
final class Transaction extends Model
{
    use Auditable;
    use BelongsToBusiness;

    protected $fillable = [
        'type',
        'status',
        'occurred_at',
        'counterparty_id',
        'method',
        'reference',
        'description',
        'draft_payload',
        'profit_method',
        'profit_status',
        'profit_currency_id',
        'customer_rate',
        'cost_rate',
        'margin_basis',
        'profit_value',
        'customer_value',
        'cost_value',
        'gross_profit',
        'fees_charged',
        'expenses_amount',
        'commissions_amount',
        'net_profit',
        'idempotency_key',
        'reversal_of_transaction_id',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'method' => MovementMethod::class,
            'profit_method' => ProfitMethod::class,
            'margin_basis' => MarginBasis::class,
            'profit_status' => ProfitStatus::class,
            'draft_payload' => 'array',
            'occurred_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class)->orderBy('sequence');
    }

    /** @return HasMany<TransactionLeg, $this> */
    public function legs(): HasMany
    {
        return $this->hasMany(TransactionLeg::class)->orderBy('sequence');
    }

    /** @return BelongsTo<Counterparty, $this> */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    /**
     * The transaction this one reverses, if it is a reversal.
     *
     * @return BelongsTo<self, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_transaction_id');
    }

    /**
     * The reversal of this transaction, if one exists.
     *
     * @return HasMany<self, $this>
     */
    public function reversedBy(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_transaction_id');
    }

    public function isDraft(): bool
    {
        return $this->status === TransactionStatus::Draft;
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_transaction_id !== null;
    }

    /**
     * The idempotency key is an internal deduplication token, not business data.
     * Recording changes to it in the audit trail would be noise.
     *
     * @return list<string>
     */
    public function auditIgnored(): array
    {
        return ['idempotency_key'];
    }
}
