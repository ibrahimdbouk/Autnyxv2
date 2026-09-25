<?php

namespace App\Platform\Extensibility;

use Illuminate\Support\Facades\DB;

/**
 * W10 (WP10.6) — the variables a tenant's formulas may name.
 *
 * Two grains:
 *   position  one store × SKU (custom RULES are evaluated here, and each hit
 *             becomes an anomaly on that position);
 *   tenant    the whole business (custom KPIs are evaluated here).
 *
 * Every sales window is anchored on the tenant's latest sales date, not the
 * wall clock, exactly like the built-in rules — a feed that is two days late
 * does not make every SKU look like it stopped selling.
 */
final class CustomVariables
{
    /** name => [label, unit] */
    public const POSITION = [
        'units_7d'             => ['Units sold, last 7 days', 'count'],
        'units_28d'            => ['Units sold, last 28 days', 'count'],
        'units_prev_28d'       => ['Units sold, the 28 days before that', 'count'],
        'revenue_7d'           => ['Revenue, last 7 days', 'money'],
        'revenue_28d'          => ['Revenue, last 28 days', 'money'],
        'avg_daily_units_28d'  => ['Average units a day, last 28 days', 'count'],
        'trend_pct'            => ['Units last 28 days vs the 28 before, %', 'percent'],
        'days_since_last_sale' => ['Days since the last sale (within a year)', 'days'],
        'on_hand'              => ['Units on hand', 'count'],
        'on_order'             => ['Units on order', 'count'],
        'reorder_point'        => ['Reorder point', 'count'],
        'safety_stock'         => ['Safety stock', 'count'],
        'days_of_cover'        => ['Days of cover (on hand ÷ average daily units)', 'days'],
        'unit_cost'            => ['Unit cost', 'money'],
        'selling_price'        => ['Selling price (product master, else average realised)', 'money'],
        'margin_pct'           => ['Margin, %', 'percent'],
        'stock_value'          => ['Stock value at cost', 'money'],
    ];

    public const TENANT = [
        'revenue_7d'             => ['Revenue, last 7 days', 'money'],
        'revenue_28d'            => ['Revenue, last 28 days', 'money'],
        'units_7d'               => ['Units sold, last 7 days', 'count'],
        'units_28d'              => ['Units sold, last 28 days', 'count'],
        'skus_selling_28d'       => ['SKUs that sold, last 28 days', 'count'],
        'stores'                 => ['Stores', 'count'],
        'stock_value'            => ['Stock value at cost', 'money'],
        'stock_positions'        => ['Store × SKU stock positions', 'count'],
        'out_of_stock_positions' => ['Positions with nothing on hand', 'count'],
        'anomalies_open'         => ['Open anomalies', 'count'],
        'open_investigations'    => ['Open investigations', 'count'],
        'revenue_at_risk'        => ['Revenue at risk (open investigations)', 'money'],
        'recovered_mtd'          => ['Recovered this month (measured)', 'money'],
    ];

    /** @return array<int,string> */
    public static function names(string $grain): array
    {
        return array_keys($grain === 'tenant' ? self::TENANT : self::POSITION);
    }

    /** Markdown-ish help for a form: "name — label" per line. */
    public static function help(string $grain): string
    {
        return collect($grain === 'tenant' ? self::TENANT : self::POSITION)
            ->map(fn ($v, $k) => "{$k} — {$v[0]}")->implode("\n");
    }

    /**
     * Every active store × SKU position (sold within a year, or holding stock),
     * streamed, with its variables.
     *
     * @return \Generator<int,array{store_id:?int,sku:string,product_id:?int,vars:array<string,float|null>}>
     */
    public static function positions(int $tenantId): \Generator
    {
        $asOf = DB::table('sales_daily')->where('tenant_id', $tenantId)->max('date');
        $d = $asOf ? substr((string) $asOf, 0, 10) : now()->toDateString();

        $rows = DB::cursor(
            "WITH s AS (
                SELECT store_id, sku,
                       SUM(units_sold) FILTER (WHERE date > ?::date - 7)  AS u7,
                       SUM(units_sold) FILTER (WHERE date > ?::date - 28) AS u28,
                       SUM(units_sold) FILTER (WHERE date > ?::date - 56 AND date <= ?::date - 28) AS up28,
                       SUM(revenue)    FILTER (WHERE date > ?::date - 7)  AS r7,
                       SUM(revenue)    FILTER (WHERE date > ?::date - 28) AS r28,
                       MAX(date)       FILTER (WHERE units_sold > 0)      AS last_sale
                  FROM sales_daily
                 WHERE tenant_id = ? AND date > ?::date - 365 AND date <= ?::date
                 GROUP BY store_id, sku
             ),
             i AS (
                SELECT store_id, sku, SUM(on_hand_qty) AS oh, SUM(on_order_qty) AS oo,
                       MAX(reorder_point) AS rop, MAX(safety_stock) AS ss, MAX(unit_cost) AS cost,
                       SUM(inventory_value) AS val
                  FROM inventory_current WHERE tenant_id = ?
                 GROUP BY store_id, sku
             ),
             pos AS (
                SELECT COALESCE(s.store_id, i.store_id) AS store_id, COALESCE(s.sku, i.sku) AS sku,
                       s.u7, s.u28, s.up28, s.r7, s.r28, s.last_sale, i.oh, i.oo, i.rop, i.ss, i.cost, i.val
                  FROM s FULL OUTER JOIN i ON i.sku = s.sku AND i.store_id IS NOT DISTINCT FROM s.store_id
             )
             SELECT pos.*, p.id AS product_id, p.unit_cost AS p_cost, p.selling_price AS p_price,
                    r.reorder_point AS r_rop, r.safety_stock AS r_ss,
                    (?::date - pos.last_sale) AS since_sale
               FROM pos
               LEFT JOIN products p ON p.tenant_id = ? AND p.sku = pos.sku
               LEFT JOIN sku_replenishment r ON r.tenant_id = ? AND r.sku = pos.sku AND r.store_id IS NOT DISTINCT FROM pos.store_id
              ORDER BY pos.store_id NULLS FIRST, pos.sku",
            [$d, $d, $d, $d, $d, $d, $tenantId, $d, $d, $tenantId, $d, $tenantId, $tenantId]
        );

        foreach ($rows as $r) {
            yield [
                'store_id'   => $r->store_id !== null ? (int) $r->store_id : null,
                'sku'        => (string) $r->sku,
                'product_id' => $r->product_id !== null ? (int) $r->product_id : null,
                'vars'       => self::derive($r),
            ];
        }
    }

    /** @return array<string,float|null> */
    public static function derive(object $r): array
    {
        $n = fn ($v) => $v === null ? null : (float) $v;
        $u7 = (float) ($r->u7 ?? 0);
        $u28 = (float) ($r->u28 ?? 0);
        $up28 = (float) ($r->up28 ?? 0);
        $r28 = (float) ($r->r28 ?? 0);
        $avg = $u28 / 28;
        $onHand = $n($r->oh);
        $cost = $n($r->cost) ?? $n($r->p_cost);
        $price = $n($r->p_price) ?? ($u28 > 0 ? $r28 / $u28 : null);

        return [
            'units_7d'             => $u7,
            'units_28d'            => $u28,
            'units_prev_28d'       => $up28,
            'revenue_7d'           => (float) ($r->r7 ?? 0),
            'revenue_28d'          => $r28,
            'avg_daily_units_28d'  => round($avg, 4),
            'trend_pct'            => $up28 > 0 ? round(($u28 - $up28) / $up28 * 100, 2) : null,
            'days_since_last_sale' => $r->since_sale !== null ? (float) $r->since_sale : null,
            'on_hand'              => $onHand,
            'on_order'             => $n($r->oo),
            'reorder_point'        => $n($r->rop) ?? $n($r->r_rop),
            'safety_stock'         => $n($r->ss) ?? $n($r->r_ss),
            'days_of_cover'        => $onHand !== null && $avg > 0 ? round($onHand / $avg, 2) : null,
            'unit_cost'            => $cost,
            'selling_price'        => $price !== null ? round($price, 4) : null,
            'margin_pct'           => $price !== null && $price > 0 && $cost !== null ? round(($price - $cost) / $price * 100, 2) : null,
            'stock_value'          => $n($r->val) ?? ($onHand !== null && $cost !== null ? round($onHand * $cost, 2) : null),
        ];
    }

    /** @return array<string,float|int> */
    public static function tenant(int $tenantId): array
    {
        $asOf = DB::table('sales_daily')->where('tenant_id', $tenantId)->max('date');
        $d = $asOf ? substr((string) $asOf, 0, 10) : now()->toDateString();

        $s = DB::selectOne(
            "SELECT COALESCE(SUM(revenue)    FILTER (WHERE date > ?::date - 7), 0) AS r7,
                    COALESCE(SUM(revenue), 0) AS r28,
                    COALESCE(SUM(units_sold) FILTER (WHERE date > ?::date - 7), 0) AS u7,
                    COALESCE(SUM(units_sold), 0) AS u28,
                    COUNT(DISTINCT sku) FILTER (WHERE units_sold > 0) AS skus
               FROM sales_daily WHERE tenant_id = ? AND date > ?::date - 28 AND date <= ?::date",
            [$d, $d, $tenantId, $d, $d]
        );
        $i = DB::selectOne(
            "SELECT COALESCE(SUM(COALESCE(c.inventory_value, c.on_hand_qty * COALESCE(c.unit_cost, p.unit_cost))), 0) AS val,
                    COUNT(*) AS n, COUNT(*) FILTER (WHERE c.on_hand_qty <= 0) AS oos
               FROM inventory_current c LEFT JOIN products p ON p.tenant_id = c.tenant_id AND p.sku = c.sku
              WHERE c.tenant_id = ?",
            [$tenantId]
        );
        $kpi = app(\App\Services\Metrics\DashboardMetrics::class)->forTenant($tenantId)['kpi'];

        return [
            'revenue_7d'             => (float) $s->r7,
            'revenue_28d'            => (float) $s->r28,
            'units_7d'               => (float) $s->u7,
            'units_28d'              => (float) $s->u28,
            'skus_selling_28d'       => (int) $s->skus,
            'stores'                 => (int) DB::table('stores')->where('tenant_id', $tenantId)->count(),
            'stock_value'            => round((float) $i->val, 2),
            'stock_positions'        => (int) $i->n,
            'out_of_stock_positions' => (int) $i->oos,
            'anomalies_open'         => (int) \App\Models\Anomaly::where('tenant_id', $tenantId)->active()->count(),
            'open_investigations'    => (int) $kpi['open'],
            'revenue_at_risk'        => (float) $kpi['revenue_at_risk'],
            'recovered_mtd'          => (float) $kpi['recovered_mtd'],
        ];
    }
}
