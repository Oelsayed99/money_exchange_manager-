<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Money\Exceptions\CurrencyMismatch;
use App\Domain\Money\Money;
use App\Enums\BalanceBucket;
use App\Enums\CounterpartyType;
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
     * The declared opening position in one bucket and one currency.
     *
     * Null means no position was declared, which is not the same as a declared zero:
     * one is silence, the other is a statement that the parties were square.
     */
    public function openingBalance(BalanceBucket $bucket, Currency $currency): ?Money
    {
        return $this->openingBalances()
            ->where('bucket', $bucket->value)
            ->where('currency_id', $currency->getKey())
            ->first()
            ?->amount;
    }

    /**
     * Declare an opening position.
     *
     * Negative amounts are refused. A negative receivable is a payable, and letting
     * one be recorded as the other would quietly undo the separation this model exists
     * to maintain — the caller must say which side they mean.
     */
    public function setOpeningBalance(BalanceBucket $bucket, Currency $currency, Money $amount): CounterpartyOpeningBalance
    {
        if (! $amount->currency->is($currency->spec())) {
            throw CurrencyMismatch::between($amount->currency, $currency->spec(), 'declare an opening balance in');
        }

        if ($amount->isNegative()) {
            throw new \InvalidArgumentException(
                "An opening {$bucket->value} balance cannot be negative. "
                ."A negative {$bucket->value} is a {$bucket->mirror()->value}; record it there instead."
            );
        }

        /** @var CounterpartyOpeningBalance $balance */
        $balance = $this->openingBalances()->updateOrCreate(
            ['bucket' => $bucket->value, 'currency_id' => $currency->getKey()],
            ['amount' => $amount->toStorageString()],
        );

        return $balance;
    }

    /**
     * Every declared opening position, grouped by bucket.
     *
     * Returned grouped rather than summed: a statement shows what is owed and what is
     * held side by side, and the reader draws their own conclusion.
     *
     * @return array<string, array<string, string>> bucket => (currency code => amount)
     */
    public function openingPositions(): array
    {
        $rows = $this->openingBalances()->with('currency')->get();

        $positions = [];

        // Iterating the enum rather than the query result gives a stable order that
        // means something — assets before liabilities — instead of whatever order the
        // rows happen to come back in.
        foreach (BalanceBucket::cases() as $bucket) {
            foreach ($rows->where('bucket', $bucket) as $balance) {
                $currency = $balance->currency;

                if ($currency === null || $balance->amount === null) {
                    continue;
                }

                $positions[$bucket->value][$currency->code] = $balance->amount->toDisplayString();
            }
        }

        return $positions;
    }
}
