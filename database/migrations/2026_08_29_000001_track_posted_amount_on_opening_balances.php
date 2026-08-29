<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a declared opening position has actually reached the ledger.
 *
 * Until now a position typed on a counterparty was a note on the record and nothing
 * more: no entry, no date, nothing in the transaction list. It is now posted, which
 * means the record has to say how much of it was — otherwise editing a figure could not
 * tell "raise this from 900,000 to 950,000" from "post 950,000 for the first time".
 *
 * The difference between `amount` and `posted_amount` is what still owes the ledger a
 * transaction. Existing rows start at zero, because that is what they are: declared and
 * never posted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('counterparty_opening_balances', function (Blueprint $table): void {
            $table->decimal('posted_amount', 28, Money::SCALE)->default('0')->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('counterparty_opening_balances', function (Blueprint $table): void {
            $table->dropColumn('posted_amount');
        });
    }
};
