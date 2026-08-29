<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parties the business deals with, and their opening positions (Section 5).
 *
 * There is deliberately no `balance` column anywhere here. A counterparty's position
 * is held per bucket per currency, because Section 5 forbids collapsing custody,
 * receivable and payable into one figure — a party can owe money and hold money at the
 * same time, and netting them destroys the information needed to act on either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counterparties', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('type', 30);

            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();

            // ISO 3166-1 alpha-2.
            $table->string('country', 2)->nullable();

            $table->foreignId('preferred_currency_id')->nullable()
                ->constrained('currencies')->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'name']);
            $table->index('type');
        });

        Schema::create('counterparty_opening_balances', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('counterparty_id')->constrained()->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();

            $table->string('bucket', 20);
            $table->decimal('amount', 28, Money::SCALE)->default(0);

            $table->timestamps();

            // One declared opening position per party, per bucket, per currency. The
            // unique key is what makes it structurally impossible to hold two
            // contradictory opening figures for the same thing.
            $table->unique(['counterparty_id', 'bucket', 'currency_id'], 'counterparty_opening_unique');
        });

        // Section 5, enforced by the database rather than only by an enum cast: an
        // unrecognised bucket would be a position nobody reports on.
        DB::statement(sprintf(
            "ALTER TABLE counterparty_opening_balances ADD CONSTRAINT chk_counterparty_bucket CHECK (bucket IN ('%s'))",
            implode("','", ['custody', 'receivable', 'payable', 'credit_trust']),
        ));

        // Deferred from Step 2.1 so the column arrives with its foreign key rather than
        // dangling: credit/trust, customer balance and partner custody accounts each
        // belong to a specific party.
        Schema::table('accounts', function (Blueprint $table): void {
            $table->foreignId('counterparty_id')->nullable()->after('type')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropForeign(['counterparty_id']);
            $table->dropColumn('counterparty_id');
        });

        Schema::dropIfExists('counterparty_opening_balances');
        Schema::dropIfExists('counterparties');
    }
};
