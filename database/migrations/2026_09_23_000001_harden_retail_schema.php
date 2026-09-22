<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema hardening — round out the core retail entities with the fields a real
 * grocer's feeds routinely carry, so ingestion never has to drop data for lack of
 * a column. Every column is NULLABLE and additive: nothing existing changes, no
 * import breaks (an unmapped field just stays null), and detection is untouched.
 * Each add is guarded by hasColumn so the migration is safe to re-run.
 *
 * Grouped by entity: stores (geo + operating attrs), products (merchandising),
 * suppliers (location + commercial terms), sales (channel + cost), inventory
 * (safety/allocation/lot), purchase orders (commercial), returns (channel/condition).
 */
return new class extends Migration
{
    /**
     * Add the given nullable columns to a table, skipping any that already exist.
     *
     * @param array<string, callable(Blueprint):void> $columns
     */
    private function addColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $missing = array_filter(
            $columns,
            fn ($_, $name) => ! Schema::hasColumn($table, $name),
            ARRAY_FILTER_USE_BOTH,
        );
        if ($missing === []) {
            return;
        }
        Schema::table($table, function (Blueprint $t) use ($missing) {
            foreach ($missing as $define) {
                $define($t);
            }
        });
    }

    public function up(): void
    {
        // ── Stores: geography + operating attributes ─────────────────────────
        $this->addColumns('stores', [
            'postal_code'    => fn (Blueprint $t) => $t->string('postal_code')->nullable(),
            'latitude'       => fn (Blueprint $t) => $t->decimal('latitude', 10, 7)->nullable(),
            'longitude'      => fn (Blueprint $t) => $t->decimal('longitude', 10, 7)->nullable(),
            'phone'          => fn (Blueprint $t) => $t->string('phone')->nullable(),
            'email'          => fn (Blueprint $t) => $t->string('email')->nullable(),
            'timezone'       => fn (Blueprint $t) => $t->string('timezone')->nullable(),
            'currency'       => fn (Blueprint $t) => $t->string('currency', 3)->nullable(),
            'banner'         => fn (Blueprint $t) => $t->string('banner')->nullable(), // chain / fascia
            'status'         => fn (Blueprint $t) => $t->string('status')->nullable(), // active | closed | remodel
            'opened_on'      => fn (Blueprint $t) => $t->date('opened_on')->nullable(),
            'sales_area_sqm' => fn (Blueprint $t) => $t->decimal('sales_area_sqm', 12, 2)->nullable(),
        ]);

        // ── Products: merchandising attributes ───────────────────────────────
        $this->addColumns('products', [
            'department' => fn (Blueprint $t) => $t->string('department')->nullable(),
            'uom'        => fn (Blueprint $t) => $t->string('uom')->nullable(), // each | kg | case ...
            'weight_grams' => fn (Blueprint $t) => $t->decimal('weight_grams', 12, 2)->nullable(),
            'tax_rate'   => fn (Blueprint $t) => $t->decimal('tax_rate', 6, 3)->nullable(),
            'gtin'       => fn (Blueprint $t) => $t->string('gtin')->nullable(), // global trade item no.
            'season'     => fn (Blueprint $t) => $t->string('season')->nullable(),
            'status'     => fn (Blueprint $t) => $t->string('status')->nullable(), // active | discontinued
        ]);

        // ── Suppliers: location + commercial terms ───────────────────────────
        $this->addColumns('suppliers', [
            'country'         => fn (Blueprint $t) => $t->string('country')->nullable(),
            'region'          => fn (Blueprint $t) => $t->string('region')->nullable(),
            'city'            => fn (Blueprint $t) => $t->string('city')->nullable(),
            'currency'        => fn (Blueprint $t) => $t->string('currency', 3)->nullable(),
            'payment_terms'   => fn (Blueprint $t) => $t->string('payment_terms')->nullable(), // e.g. NET30
            'min_order_value' => fn (Blueprint $t) => $t->decimal('min_order_value', 14, 2)->nullable(),
            'website'         => fn (Blueprint $t) => $t->string('website')->nullable(),
            'status'          => fn (Blueprint $t) => $t->string('status')->nullable(),
        ]);

        // ── Sales transactions: channel + cost/margin inputs ─────────────────
        $this->addColumns('sales_transactions', [
            'channel'       => fn (Blueprint $t) => $t->string('channel')->nullable(), // store | online | ...
            'cost_amount'   => fn (Blueprint $t) => $t->decimal('cost_amount', 14, 4)->nullable(),
            'currency'      => fn (Blueprint $t) => $t->string('currency', 3)->nullable(),
            'customer_ref'  => fn (Blueprint $t) => $t->string('customer_ref')->nullable(),
            'promotion_ref' => fn (Blueprint $t) => $t->string('promotion_ref')->nullable(),
        ]);

        // ── Inventory: safety / allocation / lot ─────────────────────────────
        $this->addColumns('inventory_levels', [
            'safety_stock'  => fn (Blueprint $t) => $t->decimal('safety_stock', 12, 2)->nullable(),
            'allocated_qty' => fn (Blueprint $t) => $t->decimal('allocated_qty', 12, 2)->nullable(),
            'in_transit_qty'=> fn (Blueprint $t) => $t->decimal('in_transit_qty', 12, 2)->nullable(),
            'unit_cost'     => fn (Blueprint $t) => $t->decimal('unit_cost', 12, 4)->nullable(),
            'batch_ref'     => fn (Blueprint $t) => $t->string('batch_ref')->nullable(),
            'expiry_date'   => fn (Blueprint $t) => $t->date('expiry_date')->nullable(),
        ]);

        // ── Purchase orders: commercial ──────────────────────────────────────
        $this->addColumns('purchase_orders', [
            'currency' => fn (Blueprint $t) => $t->string('currency', 3)->nullable(),
            'status'   => fn (Blueprint $t) => $t->string('status')->nullable(), // open | received | cancelled
            'buyer'    => fn (Blueprint $t) => $t->string('buyer')->nullable(),
        ]);

        // ── Sales returns: channel + condition ───────────────────────────────
        $this->addColumns('sales_returns', [
            'channel'                 => fn (Blueprint $t) => $t->string('channel')->nullable(),
            'condition'               => fn (Blueprint $t) => $t->string('condition')->nullable(), // resellable | damaged
            'original_transaction_ref'=> fn (Blueprint $t) => $t->string('original_transaction_ref')->nullable(),
        ]);
    }

    public function down(): void
    {
        $drops = [
            'stores'             => ['postal_code', 'latitude', 'longitude', 'phone', 'email', 'timezone', 'currency', 'banner', 'status', 'opened_on', 'sales_area_sqm'],
            'products'           => ['department', 'uom', 'weight_grams', 'tax_rate', 'gtin', 'season', 'status'],
            'suppliers'          => ['country', 'region', 'city', 'currency', 'payment_terms', 'min_order_value', 'website', 'status'],
            'sales_transactions' => ['channel', 'cost_amount', 'currency', 'customer_ref', 'promotion_ref'],
            'inventory_levels'   => ['safety_stock', 'allocated_qty', 'in_transit_qty', 'unit_cost', 'batch_ref', 'expiry_date'],
            'purchase_orders'    => ['currency', 'status', 'buyer'],
            'sales_returns'      => ['channel', 'condition', 'original_transaction_ref'],
        ];
        foreach ($drops as $table => $cols) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $present = array_values(array_filter($cols, fn ($c) => Schema::hasColumn($table, $c)));
            if ($present !== []) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
            }
        }
    }
};
