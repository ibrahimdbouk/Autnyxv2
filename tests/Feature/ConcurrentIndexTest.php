<?php

namespace Tests\Feature;

use App\Support\Database\ConcurrentIndex;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** WP6.1 — the index helper is idempotent and the scale indexes exist. */
class ConcurrentIndexTest extends TestCase
{
    public function test_create_is_idempotent_and_drop_removes(): void
    {
        $this->assertSame('created', ConcurrentIndex::create('zz_probe_idx', 'stores', '(tenant_id, code)', 'code IS NOT NULL'));
        $this->assertSame('exists', ConcurrentIndex::create('zz_probe_idx', 'stores', '(tenant_id, code)', 'code IS NOT NULL'));
        $this->assertSame('valid', ConcurrentIndex::state('zz_probe_idx'));

        ConcurrentIndex::drop('zz_probe_idx');
        $this->assertNull(ConcurrentIndex::state('zz_probe_idx'));
    }

    public function test_the_scale_indexes_are_in_place(): void
    {
        foreach (['sales_tx_store_idx', 'sales_tx_product_idx', 'sales_tx_tenant_store_date', 'sales_tx_tenant_sku_date',
            'inv_levels_store_idx', 'po_supplier_idx', 'returns_tenant_sku_date', 'anomalies_tenant_detected',
            'anomalies_live_identity', 'products_name_trgm', 'investigations_title_trgm'] as $index) {
            $this->assertSame('valid', ConcurrentIndex::state($index), $index);
        }
        $this->assertNull(ConcurrentIndex::state('idx_sales_daily_demand'), 'duplicate index dropped');
    }

    public function test_a_contains_search_can_use_the_trigram_index(): void
    {
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = collect(DB::select("EXPLAIN SELECT id FROM products WHERE name ILIKE '%milk%'"))->pluck('QUERY PLAN')->implode("\n");
        $this->assertStringContainsString('products_name_trgm', $plan);
    }
}
