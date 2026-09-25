<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP6.2 (audit H20) — the CURRENT stock position per (tenant, store, SKU):
 * the newest snapshot of that position with every lot / bin of that snapshot
 * summed. `inventory_levels` stays the history (one row per lot per snapshot
 * date) and feeds shrink / reorder-point history only; everything that asks
 * "how much is on hand now" reads this table.
 *
 * Maintained by App\Services\Inventory\InventoryCurrentService — per import,
 * per rollback, per Eloquent write — and rebuildable with
 * `php artisan inventory:rebuild-current`. Positions with no store are not
 * held (no rule can judge a position it cannot place).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_current', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location')->nullable();
            $table->date('as_of_date')->nullable();
            $table->decimal('on_hand_qty', 18, 4)->default(0);
            $table->decimal('on_order_qty', 18, 4)->nullable();
            $table->decimal('allocated_qty', 18, 4)->nullable();
            $table->decimal('in_transit_qty', 18, 4)->nullable();
            $table->decimal('inventory_value', 18, 4)->nullable();
            $table->decimal('reorder_point', 18, 4)->nullable();
            $table->decimal('safety_stock', 18, 4)->nullable();
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->date('earliest_expiry')->nullable();
            // The snapshot before this one (shrink = a drop sales don't explain).
            $table->date('prev_as_of_date')->nullable();
            $table->decimal('prev_on_hand_qty', 18, 4)->nullable();
            // First snapshot of the current unbroken run of this reorder point.
            $table->date('reorder_point_since')->nullable();
            $table->unsignedInteger('lots')->default(1);
            $table->unsignedBigInteger('import_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'store_id', 'sku'], 'inventory_current_position');
            $table->index(['tenant_id', 'sku'], 'inventory_current_tenant_sku');
            $table->index(['tenant_id', 'as_of_date'], 'inventory_current_tenant_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_current');
    }
};
