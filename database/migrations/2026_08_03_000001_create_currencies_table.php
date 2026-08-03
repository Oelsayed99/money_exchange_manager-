<?php

declare(strict_types=1);

use App\Domain\Money\CurrencySpec;
use App\Domain\Money\RoundingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();

            // 12 rather than 3: Section 1 requires administrators to add currencies
            // without a code change, and that must not assume every future currency
            // carries a three-letter ISO 4217 code.
            $table->string('code', 12)->unique();

            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('symbol', 16)->nullable();

            // Section 3: precision is defined independently per currency.
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->string('rounding_mode', 20)->default(RoundingMode::HalfUp->value);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        // Section 19 validation, enforced at the database rather than only in PHP: a
        // currency can never declare more precision than Money stores, because the
        // extra digits would be unrepresentable and silently lost.
        DB::statement(sprintf(
            'ALTER TABLE currencies ADD CONSTRAINT chk_currencies_decimal_places CHECK (decimal_places BETWEEN 0 AND %d)',
            CurrencySpec::MAX_DECIMAL_PLACES,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
