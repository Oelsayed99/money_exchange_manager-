<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\CurrencySpec;
use App\Domain\Money\Money;
use App\Domain\Money\RoundingMode;
use Database\Factories\CurrencyFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An administrator-managed currency.
 *
 * Adding a currency is a data operation, never a code change (Section 1). Precision and
 * rounding policy live here per currency (Section 3) and are handed to the domain layer
 * as an immutable CurrencySpec.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $name_ar
 * @property string|null $symbol
 * @property int $decimal_places
 * @property RoundingMode $rounding_mode
 * @property bool $is_active
 * @property int $sort_order
 *
 * @method static CurrencyFactory factory(...$parameters)
 */
final class Currency extends Model
{
    /** @use HasFactory<CurrencyFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'name_ar',
        'symbol',
        'decimal_places',
        'rounding_mode',
        'is_active',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'rounding_mode' => RoundingMode::class,
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
            roundingMode: $this->rounding_mode,
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
