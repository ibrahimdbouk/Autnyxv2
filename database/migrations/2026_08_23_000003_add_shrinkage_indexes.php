<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the single-pass inventory_shrinkage query.
 *
 * The rule pairs each (sku, location)'s latest two snapshots with a LEAD() window
 * and, per dropped pair, sums sales in that pair's own date window via a LATERAL
 * join. These two indexes back exactly those access paths so the window needs no
 * full sort and each sales lookup is an index range scan (the data has ~90 daily
 * snapshot dates and thousands of distinct windows, so both matter).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Window: PARTITION BY (sku, location) ORDER BY as_of_date DESC.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_inv_pair_snapshot ON inventory_levels (tenant_id, sku, location, as_of_date DESC NULLS LAST)');

        // Lateral sales-in-window sum: WHERE tenant_id = ? AND sku = ? AND date BETWEEN ? AND ?.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_sales_daily_sku_date ON sales_daily (tenant_id, sku, date)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_inv_pair_snapshot');
        DB::statement('DROP INDEX IF EXISTS idx_sales_daily_sku_date');
    }
};
