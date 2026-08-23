<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sales_daily — an incrementally-maintained daily aggregate of raw sales
 * transactions, per tenant / store / SKU / day. Detection rules (starting with
 * cannibalization) read from this instead of re-scanning millions of raw POS
 * rows on every run. Populated incrementally from the date range of each import
 * (never a full historical rebuild), so it scales with new data, not total data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku');
            $table->date('date');
            $table->decimal('units_sold', 16, 4)->default(0);
            $table->decimal('revenue', 18, 4)->default(0);
            $table->unsignedInteger('transaction_count')->default(0);
            $table->timestamps();

            // Idempotent upsert target: one row per tenant/store/sku/day.
            // (A null store_id collapses to a single bucket per sku/day.)
            $table->unique(['tenant_id', 'store_id', 'sku', 'date'], 'sales_daily_unique');

            // Recent-window scans by tenant (+ store) over a date range.
            $table->index(['tenant_id', 'date'], 'sales_daily_tenant_date');
            $table->index(['tenant_id', 'store_id', 'date'], 'sales_daily_tenant_store_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_daily');
    }
};
