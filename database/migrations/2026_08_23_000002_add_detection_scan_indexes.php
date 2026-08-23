<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes that turn the detection engine's hot scans into index scans.
 *
 * The engine now reads the LATEST inventory snapshot per (tenant, store, sku)
 * with a Postgres DISTINCT ON, and aggregates recent demand from sales_daily by
 * date range. These composite indexes back exactly those access paths so the
 * work scales with distinct (store, sku) combos instead of the full history.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Latest-snapshot pick: DISTINCT ON (store_id, sku) ORDER BY as_of_date DESC NULLS LAST.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_inv_latest_snapshot ON inventory_levels (tenant_id, store_id, sku, as_of_date DESC NULLS LAST)');

        // Recent-demand aggregate: WHERE tenant_id = ? AND date >= ? GROUP BY store_id, sku.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_sales_daily_demand ON sales_daily (tenant_id, date)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_inv_latest_snapshot');
        DB::statement('DROP INDEX IF EXISTS idx_sales_daily_demand');
    }
};
