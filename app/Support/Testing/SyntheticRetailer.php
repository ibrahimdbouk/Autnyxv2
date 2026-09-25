<?php

namespace App\Support\Testing;

use Illuminate\Support\Facades\DB;

/**
 * WP6.3 — a synthetic retailer for load and equivalence tests: stores × SKUs ×
 * days of sales (sales_daily plus one receipt line per store-SKU-day for the
 * last 35 days), weekly inventory snapshots with two lots, purchase orders and
 * returns. Everything is generated in SQL (generate_series) and is
 * deterministic: two tenants seeded with the same sizes get the same data
 * (keyed on store codes and SKUs, not ids).
 */
final class SyntheticRetailer
{
    /**
     * @param  int  $signalEvery  about 1 in this many store-SKUs carries each planted
     *                            signal (collapse, spike, shrink, stock-out); a power of 2
     */
    public static function seed(int $t, int $stores, int $skus, int $days, float $density = 0.6, int $signalEvery = 256, bool $benchmark = false): void
    {
        DB::statement('SELECT setseed(0.4242)');
        $m = max(4, $signalEvery) - 1;       // sales signal mask
        $mi = max(8, 2 * $signalEvery) - 1;  // inventory signal mask
        $end   = now()->subDay()->toDateString();
        $start = now()->subDays($days)->toDateString();
        $now   = now()->toDateTimeString();

        DB::insert("INSERT INTO suppliers (tenant_id, code, name, lead_time_days, created_at, updated_at)
            SELECT ?, 'SUP' || g, 'Supplier ' || g, 5 + (g % 10), ?, ? FROM generate_series(1, 40) g", [$t, $now, $now]);
        DB::insert("INSERT INTO stores (tenant_id, code, name, region, created_at, updated_at)
            SELECT ?, 'S' || lpad(g::text, 4, '0'), 'Store ' || g, 'Region ' || (g % 6), ?, ? FROM generate_series(1, ?) g", [$t, $now, $now, $stores]);
        DB::insert("INSERT INTO products (tenant_id, sku, name, category, unit_cost, selling_price, supplier, created_at, updated_at)
            SELECT ?, 'SKU' || lpad(g::text, 6, '0'), 'Product ' || g, 'Cat ' || (g % 25),
                   round((2 + (g % 40))::numeric, 2), round((3 + (g % 40)) * 1.35, 2), 'Supplier ' || (1 + g % 40), ?, ?
            FROM generate_series(1, ?) g", [$t, $now, $now, $skus]);

        // Daily sales: a per-SKU base rate, weekly rhythm and noise; a few
        // store-SKUs collapse in the last week and a few spike (real signals).
        DB::insert("INSERT INTO sales_daily (tenant_id, store_id, sku, date, units_sold, revenue, transaction_count, created_at, updated_at)
            SELECT ?, s.id, p.sku, d::date, u, round(u * p.selling_price, 2), GREATEST(1, u / 2), ?, ?
            FROM stores s
            CROSS JOIN products p
            CROSS JOIN generate_series(?::date, ?::date, interval '1 day') d
            CROSS JOIN LATERAL (
                SELECT GREATEST(0, round(
                    (1 + (hashtext(p.sku) & 7)) * (CASE WHEN extract(dow FROM d) IN (4, 5) THEN 1.4 ELSE 1 END)
                    * (CASE WHEN d > ?::date - 7 AND (hashtext(s.code || p.sku) & {$m}) = 1 THEN 0.1
                            WHEN d > ?::date - 7 AND (hashtext(s.code || p.sku) & {$m}) = 2 THEN 4
                            " . ($benchmark ? "WHEN d > '{$end}'::date - 7 AND (hashtext(s.code || p.sku) & {$m}) = 5 THEN 3" : '') . " ELSE 1 END)
                    * (0.6 + random() * 0.8 + 0 * s.id)))::int AS u
            ) x
            WHERE s.tenant_id = ? AND p.tenant_id = ? AND random() < ?",
            [$t, $now, $now, $start, $end, $end, $end, $t, $t, $density]);

        // W10: planted promotions (a 3× lift in the last week) are on the calendar.
        if ($benchmark) {
            DB::insert("INSERT INTO promotions (tenant_id, promotion_ref, sku, store_id, starts_on, ends_on, mechanic, created_at, updated_at)
                SELECT ?, 'PROMO-' || s.code, p.sku, s.id, ?::date - 6, ?::date, 'price cut', ?, ?
                FROM stores s CROSS JOIN products p
                WHERE s.tenant_id = ? AND p.tenant_id = ? AND (hashtext(s.code || p.sku) & {$m}) = 5",
                [$t, $end, $end, $now, $now, $t, $t]);
        }

        // One receipt line per store-SKU-day for the last 35 days (price rules read lines).
        DB::insert("INSERT INTO sales_transactions (tenant_id, store_id, sku, transaction_id, line_no, date, quantity, unit_price, total_amount, location, channel, created_at, updated_at)
            SELECT sd.tenant_id, sd.store_id, sd.sku, 'LT' || sd.id, 1, sd.date, sd.units_sold, p.selling_price, sd.revenue, st.name,
                   CASE WHEN (hashtext(st.code || sd.sku || sd.date::text) & 7) = 0 THEN 'online' ELSE 'store' END, ?, ?
            FROM sales_daily sd JOIN products p ON p.tenant_id = sd.tenant_id AND p.sku = sd.sku
            JOIN stores st ON st.id = sd.store_id
            WHERE sd.tenant_id = ? AND sd.date > ?::date - 35 AND sd.units_sold > 0", [$now, $now, $t, $end]);

        // Weekly snapshots, two lots per position: steady stock per position; a
        // few positions lose stock over the last weeks (shrink), a few run out.
        // W10 benchmark mode: ordinary positions hold ~50 days of cover (so a
        // stock-out flag means the planted one), and a shrink position loses
        // twice what it sells every week (half of it unexplained by sales).
        $invExpr = $benchmark
            ? "CASE
                   WHEN (hashtext(s.code || p.sku || 'inv') & {$mi}) = 3 THEN (1 + (hashtext(p.sku) & 7)) * (100 - 7 * ((d::date - ?::date) / 7))
                   WHEN (hashtext(s.code || p.sku || 'inv') & {$mi}) = 4 THEN 0
                   ELSE (1 + (hashtext(p.sku) & 7)) * 25 END"
            : "CASE
                   WHEN (hashtext(s.code || p.sku || 'inv') & {$mi}) = 3 THEN 30 - 4 * ((d::date - ?::date) / 7)
                   WHEN (hashtext(s.code || p.sku || 'inv') & {$mi}) = 4 THEN 0
                   ELSE 12 + (hashtext(s.code || p.sku) & 31) END";
        DB::insert("INSERT INTO inventory_levels (tenant_id, store_id, sku, location, as_of_date, on_hand_qty, reorder_point, unit_cost, batch_ref, created_at, updated_at)
            SELECT ?, s.id, p.sku, s.name, d::date,
                   GREATEST(0, {$invExpr}),
                   10 + (hashtext(p.sku) & 15), p.unit_cost, 'L' || lot, ?, ?
            FROM stores s CROSS JOIN products p
            CROSS JOIN generate_series(?::date, ?::date, interval '7 days') d
            CROSS JOIN generate_series(1, 2) lot
            WHERE s.tenant_id = ? AND p.tenant_id = ?", [$t, $start, $now, $now, $start, $end, $t, $t]);

        // Purchase orders on ~10% of positions; some late, some short.
        DB::insert("INSERT INTO purchase_orders (tenant_id, store_id, po_number, supplier, sku, qty_ordered, qty_received, unit_cost, order_date, expected_date, received_date, status, created_at, updated_at)
            SELECT ?, s.id, 'PO-' || s.code || '-' || p.sku, p.supplier, p.sku, 100,
                   CASE WHEN random() < 0.1 THEN 60 ELSE 100 END, p.unit_cost,
                   ?::date - 30, ?::date - 20, ?::date - (CASE WHEN random() < 0.15 THEN 5 ELSE 21 END), 'closed', ?, ?
            FROM stores s CROSS JOIN products p
            WHERE s.tenant_id = ? AND p.tenant_id = ? AND random() < 0.1", [$t, $end, $end, $end, $now, $now, $t, $t]);

        // Returns on ~1% of sale days.
        DB::insert("INSERT INTO sales_returns (tenant_id, store_id, sku, date, quantity, value, return_id, location, created_at, updated_at)
            SELECT sd.tenant_id, sd.store_id, sd.sku, sd.date, 1, round(sd.revenue / GREATEST(sd.units_sold, 1), 2), 'R' || sd.id, NULL, ?, ?
            FROM sales_daily sd WHERE sd.tenant_id = ? AND sd.units_sold > 0 AND random() < 0.01", [$now, $now, $t]);

        DB::statement('ANALYZE sales_daily');
        DB::statement('ANALYZE sales_transactions');
        DB::statement('ANALYZE inventory_levels');
    }
}
