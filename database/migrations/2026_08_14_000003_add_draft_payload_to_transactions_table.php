<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a draft is holding until it is committed.
 *
 * A draft has no ledger entries by definition (docs/posting-rules.md §5), so the
 * inputs have to live somewhere until it becomes real. Stored as a payload rather than
 * as legs, because legs cannot express everything an input carries — which bucket an
 * opening balance belongs to, for one — and a draft that loses a field on the way to
 * being posted would post the wrong thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->json('draft_payload')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn('draft_payload');
        });
    }
};
