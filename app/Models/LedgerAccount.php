<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\CurrencyRegistry;
use App\Domain\Money\CurrencySpec;
use App\Enums\EntryDirection;
use App\Enums\LedgerAccountKind;
use App\Enums\LedgerAccountSubkind;
use App\Enums\LedgerOwnerType;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account in the chart of accounts: a subkind, an owner, and a currency.
 *
 * Never created ad hoc — use LedgerAccountResolver, which guarantees that the same
 * (subkind, owner, currency) always resolves to the same row. Two accounts meaning the
 * same thing would split a balance in half without either being obviously wrong.
 *
 * @property int $id
 * @property string $code
 * @property LedgerAccountSubkind $subkind
 * @property LedgerAccountKind $kind
 * @property LedgerOwnerType $owner_type
 * @property int|null $owner_id
 * @property int $currency_id
 * @property bool $is_active
 */
final class LedgerAccount extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'code',
        'subkind',
        'kind',
        'owner_type',
        'owner_id',
        'currency_id',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subkind' => LedgerAccountSubkind::class,
            'kind' => LedgerAccountKind::class,
            'owner_type' => LedgerOwnerType::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'owner_id');
    }

    /** @return BelongsTo<Counterparty, $this> */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class, 'owner_id');
    }

    /**
     * The deterministic identity of an account.
     *
     * Built from the parts rather than stored arbitrarily, so the resolver can look up
     * an account without a query on every combination it might need.
     */
    public static function codeFor(
        LedgerAccountSubkind $subkind,
        ?int $ownerId,
        string $currencyCode,
    ): string {
        $ownerType = $subkind->ownerType();

        return sprintf(
            '%s:%s:%s:%s',
            $subkind->value,
            $ownerType->value,
            $ownerType === LedgerOwnerType::System ? '0' : (string) $ownerId,
            strtoupper($currencyCode),
        );
    }

    /** The precision and rounding policy of this account's currency. */
    public function spec(): CurrencySpec
    {
        return app(CurrencyRegistry::class)->byId($this->currency_id);
    }

    /** The direction that increases this account. */
    public function increasesOn(): EntryDirection
    {
        return $this->kind->normalBalance();
    }

    /** The signed effect of an entry in the given direction on this account. */
    public function signFor(EntryDirection $direction): int
    {
        return $this->kind->signFor($direction);
    }
}
