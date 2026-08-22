<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extended import fields drawn from the customer's real datasets — value-adding
 * columns that previously had nowhere to land (brand, pack size, store format,
 * supplier type, on-order qty, inventory value, discount, payment method,
 * store-level POs + OTIF metrics, return reference).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            if (! Schema::hasColumn('products', 'brand'))     $t->string('brand')->nullable();
            if (! Schema::hasColumn('products', 'pack_size')) $t->string('pack_size')->nullable();
        });

        Schema::table('stores', function (Blueprint $t) {
            if (! Schema::hasColumn('stores', 'format')) $t->string('format')->nullable();
        });

        Schema::table('suppliers', function (Blueprint $t) {
            if (! Schema::hasColumn('suppliers', 'type'))           $t->string('type')->nullable();
            if (! Schema::hasColumn('suppliers', 'specialization')) $t->string('specialization')->nullable();
        });

        Schema::table('inventory_levels', function (Blueprint $t) {
            if (! Schema::hasColumn('inventory_levels', 'on_order_qty'))    $t->decimal('on_order_qty', 12, 4)->nullable();
            if (! Schema::hasColumn('inventory_levels', 'inventory_value')) $t->decimal('inventory_value', 14, 4)->nullable();
        });

        Schema::table('sales_transactions', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_transactions', 'discount'))       $t->decimal('discount', 12, 4)->nullable();
            if (! Schema::hasColumn('sales_transactions', 'payment_method')) $t->string('payment_method')->nullable();
        });

        Schema::table('purchase_orders', function (Blueprint $t) {
            if (! Schema::hasColumn('purchase_orders', 'store_id')) {
                $t->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('purchase_orders', 'location'))  $t->string('location')->nullable();
            if (! Schema::hasColumn('purchase_orders', 'open_qty'))  $t->decimal('open_qty', 12, 4)->nullable();
            if (! Schema::hasColumn('purchase_orders', 'late_days')) $t->integer('late_days')->nullable();
            if (! Schema::hasColumn('purchase_orders', 'fill_rate')) $t->decimal('fill_rate', 6, 2)->nullable();
        });

        Schema::table('sales_returns', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_returns', 'return_id')) $t->string('return_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['brand', 'pack_size']));
        Schema::table('stores', fn (Blueprint $t) => $t->dropColumn(['format']));
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn(['type', 'specialization']));
        Schema::table('inventory_levels', fn (Blueprint $t) => $t->dropColumn(['on_order_qty', 'inventory_value']));
        Schema::table('sales_transactions', fn (Blueprint $t) => $t->dropColumn(['discount', 'payment_method']));
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('store_id');
            $t->dropColumn(['location', 'open_qty', 'late_days', 'fill_rate']);
        });
        Schema::table('sales_returns', fn (Blueprint $t) => $t->dropColumn(['return_id']));
    }
};
