<?php

declare(strict_types=1);

use App\Enums\LedgerAccountKind;
use App\Enums\LedgerAccountSubkind;
use App\Enums\LedgerOwnerType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The chart of accounts (docs/posting-rules.md §2).
 *
 * Every account holds exactly one currency. `Cash · Office safe · USD` and
 * `Cash · Office safe · EGP` are two rows, which is what makes per-currency balancing
 * an ordinary sum rather than a special case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();

            // A deterministic identity: subkind:ownerType:ownerId:currency.
            //
            // Uniqueness is enforced on this rather than on the parts, because MySQL
            // treats NULLs as distinct — a composite key including a nullable owner_id
            // would happily allow two identical system accounts.
            $table->string('code', 120)->unique();

            $table->string('subkind', 30);

            // Denormalised from subkind. Reporting groups by kind constantly, and
            // resolving it through the enum on every row would mean loading every row.
            $table->string('kind', 20);

            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id')->nullable();

            $table->foreignId('currency_id')->constrained()->restrictOnDelete();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index(['subkind', 'currency_id']);
            $table->index(['kind', 'currency_id']);
        });

        // An unrecognised kind or subkind would be an account no report knows how to
        // treat, and its balance would silently vanish from every total.
        DB::statement(sprintf(
            "ALTER TABLE ledger_accounts ADD CONSTRAINT chk_ledger_accounts_kind CHECK (kind IN ('%s'))",
            implode("','", LedgerAccountKind::values()),
        ));

        DB::statement(sprintf(
            "ALTER TABLE ledger_accounts ADD CONSTRAINT chk_ledger_accounts_subkind CHECK (subkind IN ('%s'))",
            implode("','", LedgerAccountSubkind::values()),
        ));

        // A system account has no owner; an owned account must have one. Getting this
        // wrong would produce a second, parallel "cash" account belonging to nobody.
        DB::statement(sprintf(
            "ALTER TABLE ledger_accounts ADD CONSTRAINT chk_ledger_accounts_owner CHECK (
                (owner_type = '%s' AND owner_id IS NULL) OR (owner_type <> '%s' AND owner_id IS NOT NULL)
            )",
            LedgerOwnerType::System->value,
            LedgerOwnerType::System->value,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
