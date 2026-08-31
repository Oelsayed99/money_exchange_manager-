<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Money\CurrencySpec;
use App\Domain\Money\Money;
use App\Models\Concerns\BelongsToBusiness;
use Database\Factories\CurrencyFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An administrator-managed currency.
 *
 * Adding a currency is a data operation, never a code change (Section 1). Display
 * precision lives here per currency (Section 3) and is handed to the domain layer as an
 * immutable CurrencySpec. It is a minimum for display, never a rounding instruction —
 * nothing in this system rounds.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $name_ar
 * @property string|null $symbol
 * @property int $decimal_places
 * @property bool $is_active
 * @property int $sort_order
 *
 * @method static CurrencyFactory factory(...$parameters)
 */
final class Currency extends Model
{
    use Auditable;
    use BelongsToBusiness;

    /** @use HasFactory<CurrencyFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'name_ar',
        'symbol',
        'decimal_places',
        'is_active',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Codes are stored and compared uppercase so that 'usd' and 'USD' can never
     * become two different currencies holding two different balances.
     *
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => strtoupper(trim($value)),
        );
    }

    /** The immutable, database-free view of this currency used by the domain layer. */
    public function spec(): CurrencySpec
    {
        return new CurrencySpec(
            code: $this->code,
            decimalPlaces: $this->decimal_places,
        );
    }

    /** Convenience factory so callers can write $usd->money('1000.00'). */
    public function money(string|int $amount): Money
    {
        return Money::of($amount, $this->spec());
    }

    public function zero(): Money
    {
        return Money::zero($this->spec());
    }
}
