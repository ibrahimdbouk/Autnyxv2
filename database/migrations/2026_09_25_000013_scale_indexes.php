<?php

use App\Support\Database\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * WP6.1 (audit H30, M22) — the indexes the target scale (300 stores × 20k SKUs)
 * needs, built CONCURRENTLY with a short lock timeout (docs/migrations.md).
 *
 *  - store_id / product_id on the 4 fact tables: the Stores / Products counts,
 *    and the ON DELETE SET NULL of a store or product, no longer scan the table.
 *  - (tenant_id, store_id, date) and (tenant_id, sku, date) for the per-store
 *    and per-SKU windows the rules and pages read. (tenant_id, sku, date)
 *    replaces the (tenant_id, sku) index it starts with.
 *  - anomalies: (tenant_id, detected_at) for lists, digests and retention; a
 *    partial index over live anomalies only (not dismissed, not resolved).
 *  - purchase_orders.supplier_id for the supplier foreign key.
 *  - pg_trgm GIN indexes behind the master / work list searches (ILIKE '%…%').
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** name => [table, columns, predicate] */
    private const INDEXES = [
        // sales_transactions
        'sales_tx_store_idx'          => ['sales_transactions', '(store_id)', 'store_id IS NOT NULL'],
        'sales_tx_product_idx'        => ['sales_transactions', '(product_id)', 'product_id IS NOT NULL'],
        'sales_tx_tenant_store_date'  => ['sales_transactions', '(tenant_id, store_id, date)', null],
        'sales_tx_tenant_sku_date'    => ['sales_transactions', '(tenant_id, sku, date)', null],
        // inventory_levels (the per-store latest-snapshot index already leads with tenant, store)
        'inv_levels_store_idx'        => ['inventory_levels', '(store_id)', 'store_id IS NOT NULL'],
        'inv_levels_product_idx'      => ['inventory_levels', '(product_id)', 'product_id IS NOT NULL'],
        'inv_levels_tenant_sku_date'  => ['inventory_levels', '(tenant_id, sku, as_of_date)', null],
        // purchase_orders
        'po_store_idx'                => ['purchase_orders', '(store_id)', 'store_id IS NOT NULL'],
        'po_product_idx'              => ['purchase_orders', '(product_id)', 'product_id IS NOT NULL'],
        'po_supplier_idx'             => ['purchase_orders', '(supplier_id)', 'supplier_id IS NOT NULL'],
        'po_tenant_store_date'        => ['purchase_orders', '(tenant_id, store_id, order_date)', null],
        'po_tenant_sku_date'          => ['purchase_orders', '(tenant_id, sku, order_date)', null],
        // sales_returns
        'returns_store_idx'           => ['sales_returns', '(store_id)', 'store_id IS NOT NULL'],
        'returns_product_idx'         => ['sales_returns', '(product_id)', 'product_id IS NOT NULL'],
        'returns_tenant_store_date'   => ['sales_returns', '(tenant_id, store_id, date)', null],
        'returns_tenant_sku_date'     => ['sales_returns', '(tenant_id, sku, date)', null],
        // anomalies
        'anomalies_tenant_detected'   => ['anomalies', '(tenant_id, detected_at)', null],
        'anomalies_live_identity'     => ['anomalies', '(tenant_id, identity_key)', "dismissed_at IS NULL AND lifecycle_state <> 'resolved'"],
    ];

    /**
     * Trigram indexes: name => [table, column]. Master and work tables only —
     * on the fact tables a GIN index would tax every import row, so their list
     * pages search a SKU / receipt / PO number exactly or by prefix instead
     * (App\Filament\Support\FactTable).
     */
    private const TRIGRAM = [
        'products_sku_trgm'           => ['products', 'sku'],
        'products_name_trgm'          => ['products', 'name'],
        'anomalies_sku_trgm'          => ['anomalies', 'sku'],
        'investigations_title_trgm'   => ['investigations', 'title'],
        'investigations_sku_trgm'     => ['investigations', 'primary_sku'],
    ];

    /** Superseded by a wider index above (same leading columns). */
    private const REDUNDANT = [
        'sales_transactions_tenant_id_sku_index', // → sales_tx_tenant_sku_date
        'inventory_levels_tenant_id_sku_index',   // → inv_levels_tenant_sku_date
        'idx_sales_daily_demand',                 // exact duplicate of sales_daily_tenant_date
        'anomalies_tenant_identity_idx',          // → anomalies_episode_unique (tenant, identity, episode)
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $cols, $where]) {
            ConcurrentIndex::create($name, $table, $cols, $where);
        }

        if (ConcurrentIndex::extension('pg_trgm')) {
            foreach (self::TRIGRAM as $name => [$table, $col]) {
                ConcurrentIndex::create($name, $table, "USING gin ({$col} gin_trgm_ops)");
            }
        }

        foreach (self::REDUNDANT as $name) {
            ConcurrentIndex::drop($name);
        }
    }

    public function down(): void
    {
        foreach (array_merge(array_keys(self::INDEXES), array_keys(self::TRIGRAM)) as $name) {
            ConcurrentIndex::drop($name);
        }
        ConcurrentIndex::create('sales_transactions_tenant_id_sku_index', 'sales_transactions', '(tenant_id, sku)');
        ConcurrentIndex::create('inventory_levels_tenant_id_sku_index', 'inventory_levels', '(tenant_id, sku)');
        ConcurrentIndex::create('idx_sales_daily_demand', 'sales_daily', '(tenant_id, date)');
        ConcurrentIndex::create('anomalies_tenant_identity_idx', 'anomalies', '(tenant_id, identity_key)');
    }
};
