<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W13 — pack sizes and PO currency.
 *
 *   products.units_per_case          selling units in one case / carton;
 *   purchase_orders.order_uom        the unit the PO line's quantity is in;
 *   purchase_orders.pack_factor      the factor applied (1 = already in units;
 *                                    null = not normalised yet);
 *   purchase_orders.unit_cost_original / fx_rate
 *                                    the cost as ordered, and the rate that
 *                                    turned it into the tenant's currency.
 *
 * Quantities and unit cost on a PO line are held in selling units and the
 * tenant's currency once normalised, so fill rate, cover and value compare
 * like with like with sales and stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->decimal('units_per_case', 14, 4)->nullable();
        });
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->string('order_uom', 20)->nullable();
            $t->decimal('pack_factor', 14, 4)->nullable();
            $t->decimal('unit_cost_original', 18, 4)->nullable();
            $t->decimal('fx_rate', 18, 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropColumn(['order_uom', 'pack_factor', 'unit_cost_original', 'fx_rate']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('units_per_case'));
    }
};
