<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B4 — derived replenishment parameters per (store, SKU).
 *
 * Computed nightly from the best-fit demand profile (rate, variability) and
 * observed supplier lead times. This is what promotes the engine from
 * "detection" to "prescription": a reorder point, safety stock, an order-up-to
 * level, and a suggested order quantity — even when the tenant never supplied a
 * reorder point of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sku_replenishment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->unsignedBigInteger('store_id'); // 0 = chain-level

            $table->string('supplier')->nullable();
            $table->string('segment')->nullable();

            $table->decimal('daily_rate', 12, 4)->default(0);      // expected units/day (Croston rate)
            $table->decimal('lead_time_days', 8, 2)->default(0);   // observed avg supplier lead time
            $table->decimal('safety_stock', 12, 2)->default(0);
            $table->decimal('reorder_point', 12, 2)->default(0);
            $table->decimal('order_up_to', 12, 2)->default(0);

            $table->decimal('on_hand', 12, 2)->nullable();         // snapshot at compute time
            $table->decimal('suggested_order_qty', 12, 2)->default(0);
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->decimal('order_value', 14, 2)->default(0);     // suggested_qty x unit_cost

            $table->decimal('service_level', 5, 2)->nullable();    // Z-based %, for transparency
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'sku', 'store_id']);
            $table->index(['tenant_id', 'store_id']);
            $table->index(['tenant_id', 'suggested_order_qty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_replenishment');
    }
};
