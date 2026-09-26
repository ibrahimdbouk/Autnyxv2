<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W13 — period aggregates and exchange rates.
 *
 *   sales_weekly / sales_monthly   store × SKU × ISO week (Monday) / month,
 *                                  rebuilt from sales_daily for exactly the
 *                                  periods a daily rebuild touched;
 *   fx_rates                       units of the tenant's currency per unit of
 *                                  another, from a date. Sales in another
 *                                  currency are converted into the tenant's
 *                                  when the daily aggregate is built.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_weekly' => 'week_start', 'sales_monthly' => 'month_start'] as $table => $col) {
            Schema::create($table, function (Blueprint $t) use ($col, $table) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->unsignedBigInteger('store_id');
                $t->string('sku', 100);
                $t->date($col);
                $t->decimal('units_sold', 14, 4)->default(0);
                $t->decimal('revenue', 18, 4)->default(0);
                $t->unsignedInteger('transaction_count')->default(0);
                $t->unsignedSmallInteger('days_sold')->default(0);
                $t->timestamps();

                $t->unique(['tenant_id', 'store_id', 'sku', $col], "{$table}_key");
                $t->index(['tenant_id', $col]);
                $t->index(['tenant_id', 'sku', $col]);
            });
        }

        Schema::create('fx_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('currency', 3);
            $t->date('valid_from');
            $t->decimal('rate', 18, 8);            // tenant currency per 1 unit of `currency`
            $t->string('source', 40)->nullable();  // manual | import | peg
            $t->timestamps();

            $t->unique(['tenant_id', 'currency', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
        Schema::dropIfExists('sales_monthly');
        Schema::dropIfExists('sales_weekly');
    }
};
