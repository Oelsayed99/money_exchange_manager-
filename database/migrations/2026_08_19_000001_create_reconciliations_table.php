<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciliations: does what we hold agree with what the ledger says we hold?
 *
 * `ledger:verify` proves the ledger agrees with itself. This proves it agrees with the
 * world — the cash counted in a safe, the balance a bank reports.
 *
 * Both figures are stored rather than one. The counted figure is a fact about a moment
 * and can only be recorded. The ledger figure is *also* recorded, at the moment of
 * reconciling, even though it could be recomputed later — because entries can be
 * backdated. A reconciliation saying "on 30 June the ledger held X" that silently
 * became "the ledger held Y" once somebody posted a 15 June transaction would destroy
 * the only evidence that anything moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();

            // The moment being reconciled. A date, not a range: "as of the close of
            // this day", which is how a safe is counted and how a bank statement reads.
            $table->date('as_of');

            // What was actually there, and what the ledger said was there.
            $table->decimal('counted_amount', 28, 10);
            $table->decimal('ledger_amount', 28, 10);

            // Stored rather than derived on read. It is the answer to the question the
            // record exists to ask, and a stored answer can be indexed and searched.
            $table->decimal('difference', 28, 10);

            $table->string('status', 20);

            $table->text('note')->nullable();

            // How a difference was explained, and by whom.
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            // The adjustment posted to correct a real error, if one was. A
            // reconciliation never writes a balance itself.
            $table->foreignId('adjustment_transaction_id')->nullable()
                ->constrained('transactions')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One reconciliation per account, currency and day. Counting the same safe
            // twice on one day and recording both would leave nobody able to say which
            // was the count.
            $table->unique(['account_id', 'currency_id', 'as_of']);

            $table->index(['status', 'as_of']);
        });

        // The two figures and their difference are a record of a moment. Only the
        // explanation may change afterwards, so the numbers are frozen here rather
        // than left to a policy somebody could forget to apply.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reconciliations_figures_are_immutable
            BEFORE UPDATE ON reconciliations
            FOR EACH ROW
            BEGIN
                IF NEW.counted_amount <> OLD.counted_amount
                    OR NEW.ledger_amount <> OLD.ledger_amount
                    OR NEW.difference <> OLD.difference
                    OR NEW.as_of <> OLD.as_of
                    OR NEW.account_id <> OLD.account_id
                    OR NEW.currency_id <> OLD.currency_id
                THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'A reconciliation records what was found. Its figures cannot be edited; record a new one, or post an adjustment.';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS reconciliations_figures_are_immutable');

        Schema::dropIfExists('reconciliations');
    }
};
