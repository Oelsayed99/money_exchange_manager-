<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custody locations — where money physically sits (Section 4).
 *
 * A counterparty link belongs on some of these types (credit/trust, customer balance,
 * partner custody) and is added with the counterparties table, so that the column
 * arrives with its foreign key rather than dangling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('type', 30);

            $table->string('owner')->nullable();
            $table->string('provider')->nullable();

            // Sensitive. Masked for display, and redacted in the audit trail: an account
            // number sitting in an append-only, undeletable log is a liability.
            $table->string('identifier')->nullable();

            $table->boolean('is_active')->default(true);

            $table->string('color', 20)->nullable();
            $table->string('icon', 50)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
            $table->index('type');
        });

        Schema::create('account_currency', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            // Restricted, not cascading: a currency in use by an account must not be
            // removable, and currencies are deactivated rather than deleted anyway.
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();

            // The declared starting balance. Section 6 also lists "Opening balance" as a
            // transaction type; this column is the declaration, and Phase 3 posts it to
            // the ledger so that even the opening position has an entry behind it.
            $table->decimal('opening_balance', 28, Money::SCALE)->default(0);

            $table->timestamps();

            // One row per account per currency, or an account could hold two different
            // opening balances in the same currency.
            $table->unique(['account_id', 'currency_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_currency');
        Schema::dropIfExists('accounts');
    }
};
