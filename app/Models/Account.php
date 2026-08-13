<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Domain\Money\Money;
use App\Enums\AccountType;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A custody location: somewhere money is held (Section 4).
 *
 * An account does not carry a balance column. Balances derive from the ledger
 * (Section 7), and the only monetary figure stored here is the declared opening
 * position, held per currency on the pivot.
 *
 * @property int $id
 * @property string $name
 * @property AccountType $type
 * @property int|null $counterparty_id
 * @property string|null $owner
 * @property string|null $provider
 * @property string|null $identifier
 * @property bool $is_active
 * @property string|null $color
 * @property string|null $icon
 * @property int $sort_order
 *
 * @method static AccountFactory factory(...$parameters)
 */
final class Account extends Model
{
    use Auditable;

    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'counterparty_id',
        'owner',
        'provider',
        'identifier',
        'is_active',
        'color',
        'icon',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The party this custody location belongs to, where it belongs to one.
     *
     * A credit/trust account, a customer balance and a partner's custody are each
     * tied to somebody; a safe in the office is not.
     *
     * @return BelongsTo<Counterparty, $this>
     */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    /**
     * Currencies this account can hold, with the declared opening balance for each.
     *
     * @return BelongsToMany<Currency, $this, AccountCurrency, 'pivot'>
     */
    public function currencies(): BelongsToMany
    {
        return $this->belongsToMany(Currency::class)
            ->using(AccountCurrency::class)
            ->withPivot(['id', 'opening_balance'])
            ->withTimestamps();
    }

    public function supports(Currency $currency): bool
    {
        return $this->currencies()->whereKey($currency->getKey())->exists();
    }

    /**
     * The declared opening balance in a currency this account holds.
     *
     * Returns null rather than zero when the account does not hold the currency at
     * all: "no opening balance" and "does not deal in this currency" are different
     * facts, and collapsing them would let a typo look like a legitimate zero.
     */
    public function openingBalance(Currency $currency): ?Money
    {
        $held = $this->currencies()->whereKey($currency->getKey())->first();

        if ($held === null) {
            return null;
        }

        $pivot = $held->getAttribute('pivot');

        // Already a Money: the pivot casts the column against its own currency_id.
        return $pivot instanceof AccountCurrency ? $pivot->opening_balance : null;
    }

    /**
     * Declare this account's opening balance in a currency.
     *
     * The sanctioned way to write a Money to the pivot. Attaching pivot attributes
     * directly cannot validate the currency — Eloquent casts them before the foreign
     * keys are merged — so the check lives here, where both sides are known.
     */
    public function setOpeningBalance(Currency $currency, Money $amount): void
    {
        if (! $amount->currency->is($currency->spec())) {
            throw CurrencyMismatch::between($amount->currency, $currency->spec(), 'set an opening balance in');
        }

        $this->currencies()->syncWithoutDetaching([
            $currency->getKey() => ['opening_balance' => $amount->toStorageString()],
        ]);

        $this->unsetRelation('currencies');
    }

    /**
     * A display form of the account identifier that reveals only the last few
     * characters, so a screen or a screenshot does not carry a full account number.
     *
     * @return Attribute<string|null, never>
     */
    protected function maskedIdentifier(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                $identifier = $this->identifier;

                if ($identifier === null || $identifier === '') {
                    return null;
                }

                $visible = 4;

                if (mb_strlen($identifier) <= $visible) {
                    return str_repeat('•', mb_strlen($identifier));
                }

                return str_repeat('•', mb_strlen($identifier) - $visible)
                    .mb_substr($identifier, -$visible);
            },
        );
    }

    /**
     * The account identifier is recorded as having changed, never with its value.
     *
     * @return list<string>
     */
    public function auditRedacted(): array
    {
        return [...array_values($this->getHidden()), 'identifier'];
    }
}
