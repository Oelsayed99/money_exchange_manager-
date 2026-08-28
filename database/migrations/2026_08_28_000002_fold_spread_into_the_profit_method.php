<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Folds the spread into the profit method.
 *
 * "Spread" was a profit method that then asked a second question — units per unit, or a
 * percentage? — and the answer to that second question was the only thing that changed
 * the arithmetic. So the answers are the methods now: `per_unit` and `percentage` sit
 * in the one list beside rate difference and fixed amount, and `spread_type` is gone.
 *
 * `spread_value` becomes `profit_value`. It never only held a spread — a fixed amount
 * and a hand-entered figure went in the same column — and with the spread gone the name
 * described nothing.
 */
return new class extends Migration
{
    /** Every profit method that may be stored, after this migration. */
    private const METHODS = ['rate_difference', 'per_unit', 'percentage', 'fixed_amount', 'manual', 'none'];

    private const METHODS_BEFORE = ['rate_difference', 'fixed_amount', 'percentage', 'manual', 'none'];

    public function up(): void
    {
        // A deal recorded as a spread carried its meaning in spread_type, and dropping
        // that column would throw the meaning away — leaving a row that says
        // "percentage" without saying whether the figure beside it was a percentage.
        // Nothing here guesses. It stops.
        $ambiguous = DB::table('transactions')->whereNotNull('spread_type')->count();

        if ($ambiguous > 0) {
            throw new RuntimeException(
                "{$ambiguous} transaction(s) carry a spread_type. Restate each one as the profit "
                ."method that matches it — 'per_unit' or 'percentage' — before running this "
                .'migration. It will not guess on your behalf.'
            );
        }

        $this->allowMethods(self::METHODS);

        DB::statement('ALTER TABLE transactions DROP CHECK chk_transactions_spread_type');

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn('spread_type');
            $table->renameColumn('spread_value', 'profit_value');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->renameColumn('profit_value', 'spread_value');
            $table->string('spread_type', 32)->nullable()->after('cost_rate');
        });

        DB::statement(
            'ALTER TABLE transactions ADD CONSTRAINT chk_transactions_spread_type '
            ."CHECK (spread_type IS NULL OR spread_type IN ('per_unit','percentage'))"
        );

        $this->allowMethods(self::METHODS_BEFORE);
    }

    /** @param  list<string>  $methods */
    private function allowMethods(array $methods): void
    {
        DB::statement('ALTER TABLE transactions DROP CHECK chk_transactions_profit_method');

        DB::statement(sprintf(
            'ALTER TABLE transactions ADD CONSTRAINT chk_transactions_profit_method '
            ."CHECK (profit_method IS NULL OR profit_method IN ('%s'))",
            implode("','", $methods),
        ));
    }
};
