<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail (Section 15).
 *
 * Append-only, and enforced as such by the database rather than by convention. An
 * audit trail that the application can quietly rewrite is not evidence of anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();

            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->string('event', 20);

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // Nullable and deliberately without a foreign key. The trail has to outlive
            // the user who created it — a deleted account must not take its history
            // with it, and must not block the deletion either.
            $table->unsignedBigInteger('user_id')->nullable();

            // A snapshot of who acted, so an entry stays readable even after the user
            // row is gone and the id resolves to nothing.
            $table->string('actor_label')->nullable();

            $table->string('source', 20)->default('web');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // created_at only. There is no updated_at because an audit row is never
            // updated; see the triggers below.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id'], 'audit_logs_auditable_index');
            $table->index('user_id');
            $table->index('created_at');
            $table->index('event');
        });

        // Immutability at the database level. Application code can be changed, bypassed
        // or bypassed by accident; these cannot be, short of dropping them deliberately
        // — which is itself a visible schema change.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_no_update
            BEFORE UPDATE ON audit_logs
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'audit_logs is append-only: rows cannot be updated.';
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_no_delete
            BEFORE DELETE ON audit_logs
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'audit_logs is append-only: rows cannot be deleted.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete');

        Schema::dropIfExists('audit_logs');
    }
};
