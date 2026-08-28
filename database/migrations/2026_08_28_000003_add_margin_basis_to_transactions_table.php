<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records which leg of the deal the margin was measured on.
 *
 * Until now it was always the received leg, which is right for a sale and wrong for a
 * purchase — see ADR 0027. With both possible, a stored `customer_rate` of 51.48 no
 * longer says on its own whether it means pounds per dollar or dollars per pound, and a
 * ledger that cannot be read back is not a ledger.
 *
 * `profit_currency_id` narrows it down, but only by comparison against the two legs.
 * This says it outright.
 *
 * Existing rows are backfilled to `received`, because that is what they were: the
 * calculator had no other behaviour when they were written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('margin_basis', 16)->nullable()->after('profit_currency_id');
        });

        DB::table('transactions')->whereNotNull('profit_method')->update(['margin_basis' => 'received']);

        DB::statement(
            'ALTER TABLE transactions ADD CONSTRAINT chk_transactions_margin_basis '
            ."CHECK (margin_basis IS NULL OR margin_basis IN ('received','delivered'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transactions DROP CHECK chk_transactions_margin_basis');

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn('margin_basis');
        });
    }
};
