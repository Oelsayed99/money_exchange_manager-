<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops `fixed_amount` from the spread types.
 *
 * It computed `customer value − the figure typed`, which is exactly what
 * ProfitMethod::FixedAmount computes. The operator was being asked to choose between
 * "Fixed amount" in one list and "A flat amount for the deal" in another, and the two
 * produced the same ledger entry either way.
 *
 * The remaining two are the ones Section 3 exists to keep apart: 0.02 as units of
 * margin per unit exchanged, against 0.02 per cent.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'chk_transactions_spread_type';

    public function up(): void
    {
        // Nothing here rewrites a recorded deal. If one exists, this stops and says so:
        // the value it was recorded under is a fact about what somebody chose, and
        // silently restating it as a different profit method would change what the
        // ledger claims happened.
        $recorded = DB::table('transactions')->where('spread_type', 'fixed_amount')->count();

        if ($recorded > 0) {
            throw new RuntimeException(
                "{$recorded} transaction(s) were recorded with spread_type = 'fixed_amount'. "
                .'Restate them as profit_method = fixed_amount by hand, with a note of why, '
                .'before running this migration — this migration will not rewrite them for you.'
            );
        }

        $this->replaceConstraint(['per_unit', 'percentage']);
    }

    public function down(): void
    {
        $this->replaceConstraint(['per_unit', 'percentage', 'fixed_amount']);
    }

    /** @param  list<string>  $allowed */
    private function replaceConstraint(array $allowed): void
    {
        DB::statement('ALTER TABLE transactions DROP CHECK '.self::CONSTRAINT);

        DB::statement(sprintf(
            "ALTER TABLE transactions ADD CONSTRAINT %s CHECK (spread_type IS NULL OR spread_type IN ('%s'))",
            self::CONSTRAINT,
            implode("','", $allowed),
        ));
    }
};
