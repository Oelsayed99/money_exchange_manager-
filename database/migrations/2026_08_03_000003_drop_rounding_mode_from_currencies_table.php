<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the per-currency rounding policy.
 *
 * Nothing in this system rounds. Amounts are exact; display shows what is held; the
 * only lossy operation is division, which truncates toward zero and never inflates a
 * value. A configurable rounding rule therefore has nothing to configure.
 *
 * Added as a drop rather than by rewriting the create migration, which is already
 * published. See docs/adr/0005-no-rounding.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table): void {
            $table->dropColumn('rounding_mode');
        });
    }

    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table): void {
            $table->string('rounding_mode', 20)->default('half_up')->after('decimal_places');
        });
    }
};
