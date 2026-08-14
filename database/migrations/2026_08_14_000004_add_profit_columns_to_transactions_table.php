<?php

declare(strict_types=1);

use App\Domain\Exchange\ProfitCalculator;
use App\Domain\Money\Money;
use App\Enums\ProfitMethod;
use App\Enums\ProfitStatus;
use App\Enums\SpreadType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The profit on a deal, inputs and outputs together (Section 3).
 *
 * Both are stored deliberately. Keeping only the answer means nobody can check it;
 * keeping only the inputs means the answer changes whenever the code does — and
 * Section 3 forbids silent recalculation of old transactions. With both, a statement
 * can show why a number is what it is, and a later change to the calculator cannot
 * quietly rewrite last year's margin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('profit_method', 30)->nullable()->after('method');
            $table->string('profit_status', 20)->nullable()->after('profit_method');

            $table->foreignId('profit_currency_id')->nullable()->after('profit_status')
                ->constrained('currencies')->restrictOnDelete();

            // Inputs. Rates carry more precision than any amount, because a rate is a
            // ratio and truncating it early would move the money it produces.
            $table->decimal('customer_rate', 28, ProfitCalculator::RATE_SCALE)->nullable();
            $table->decimal('cost_rate', 28, ProfitCalculator::RATE_SCALE)->nullable();
            $table->string('spread_type', 20)->nullable();
            $table->decimal('spread_value', 28, ProfitCalculator::RATE_SCALE)->nullable();

            // Outputs.
            $table->decimal('customer_value', 28, Money::SCALE)->nullable();
            $table->decimal('cost_value', 28, Money::SCALE)->nullable();
            $table->decimal('gross_profit', 28, Money::SCALE)->nullable();
            $table->decimal('fees_charged', 28, Money::SCALE)->nullable();
            $table->decimal('expenses_amount', 28, Money::SCALE)->nullable();
            $table->decimal('commissions_amount', 28, Money::SCALE)->nullable();
            $table->decimal('net_profit', 28, Money::SCALE)->nullable();

            $table->index('profit_status');
        });

        foreach ([
            ['profit_method', ProfitMethod::values()],
            ['profit_status', ProfitStatus::values()],
            ['spread_type', SpreadType::values()],
        ] as [$column, $allowed]) {
            DB::statement(sprintf(
                "ALTER TABLE transactions ADD CONSTRAINT chk_transactions_%s CHECK (%s IS NULL OR %s IN ('%s'))",
                $column, $column, $column, implode("','", $allowed),
            ));
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropForeign(['profit_currency_id']);
            $table->dropColumn([
                'profit_method', 'profit_status', 'profit_currency_id',
                'customer_rate', 'cost_rate', 'spread_type', 'spread_value',
                'customer_value', 'cost_value', 'gross_profit',
                'fees_charged', 'expenses_amount', 'commissions_amount', 'net_profit',
            ]);
        });
    }
};
