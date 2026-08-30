<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Domain\Money\Money;
use App\Enums\CounterpartyType;
use App\Models\Concerns\BelongsToBusiness;
use Database\Factories\CounterpartyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person or organisation the business deals with (Section 5).
 *
 * Carries no single balance, by design. A party may owe money and hold money on the
 * business's behalf at the same time; those are separate positions and are read
 * separately. There is no method here that nets them together, and adding one would
 * defeat the point of keeping them apart.
 *
 * @property int $id
 * @property string $name
 * @property CounterpartyType $type
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $country
 * @property int|null $preferred_currency_id
 * @property bool $is_active
 *
 * @method static CounterpartyFactory factory(...$parameters)
 */
final class Counterparty extends Model
{
    use Auditable;
    use BelongsToBusiness;

    /** @use HasFactory<CounterpartyFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'counterparties';

    protected $fillable = [
        'name',
        'type',
        'phone',
        'email',
        'country',
        'preferred_currency_id',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CounterpartyType::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Currency, $this> */
    public function preferredCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'preferred_currency_id');
    }

    /** @return HasMany<CounterpartyOpeningBalance, $this> */
    public function openingBalances(): HasMany
    {
        return $this->hasMany(CounterpartyOpeningBalance::class);
    }

    /**
     * Custody locations that belong specifically to this party — a credit/trust
     * account, a customer balance, a partner's custody.
     *
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Where this party stood in one currency before the books began.
     *
     * Null means nothing was declared, which is not the same as a declared zero: one is
     * silence, the other says the two of you were square.
     */
    public function openingBalance(Currency $currency): ?Money
    {
        return $this->openingBalances()
            ->where('currency_id', $currency->getKey())
            ->first()
            ?->amount;
    }

    /**
     * Declare where the relationship started, as one signed figure.
     *
     * Negative is not only allowed, it is half the point: **positive means they owed
     * us**, negative that we were holding money of theirs. There used to be four
     * separate positions here and a refusal to accept a negative in any of them,
     * because a negative receivable was really a payable. With one signed balance the
     * sign carries that distinction on its own.
     */
    public function setOpeningBalance(Currency $currency, Money $amount): CounterpartyOpeningBalance
    {
        if (! $amount->currency->is($currency->spec())) {
            throw CurrencyMismatch::between($amount->currency, $currency->spec(), 'declare an opening balance in');
        }

        /** @var CounterpartyOpeningBalance $balance */
        $balance = $this->openingBalances()->updateOrCreate(
            ['currency_id' => $currency->getKey()],
            ['amount' => $amount->toStorageString()],
        );

        return $balance;
    }

    /**
     * Every declared opening position, by currency.
     *
     * @return array<string, string> currency code => signed amount
     */
    public function openingPositions(): array
    {
        $positions = [];

        foreach ($this->openingBalances()->with('currency')->get() as $balance) {
            $currency = $balance->currency;

            if ($currency instanceof Currency && $balance->amount !== null) {
                $positions[$currency->code] = $balance->amount->toDisplayString();
            }
        }

        return $positions;
    }
}
