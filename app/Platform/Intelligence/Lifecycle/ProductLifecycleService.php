<?php

namespace App\Platform\Intelligence\Lifecycle;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Platform intelligence: where each product is in its life, chain-wide and at
 * each store. Any app reads it; Assortment uses it so a product that is too new,
 * seasonal, fading everywhere or finished is not judged like an established one.
 *
 *   new          first sale (chain-wide, or at this store) within 13 weeks — and at
 *                least 4 weeks after the data starts (a product selling from the first
 *                day of the data is older than the data, not new)
 *   emerging     13–26 weeks old and still growing (last 6 weeks > 1.1 × the 6 before),
 *                with the same "after the data starts" test
 *   established  everything else
 *   declining    26+ weeks old, last 13 weeks < 0.7 × the 13 before, and falling
 *                like that in at least 60% of the stores that sell it
 *   seasonal     a season on the product file, or 60%+ of a year's units in its
 *                busiest 13 weeks (needs a year of history)
 *   end_of_life  no sale or stock anywhere for 8 weeks, or a discontinued status
 *
 * Precedence: end of life → new → seasonal → declining → emerging → established.
 * Thresholds are Autnyx-owned defaults, recalibrated from results — not tenant
 * settings. Everything is computed in the database; nothing large is held in PHP.
 */
final class ProductLifecycleService
{
    public const NEW              = 'new';
    public const EMERGING         = 'emerging';
    public const ESTABLISHED      = 'established';
    public const DECLINING        = 'declining';
    public const SEASONAL         = 'seasonal';
    public const END_OF_LIFE      = 'end_of_life';

    public const STATES = [
        self::NEW         => 'New',
        self::EMERGING    => 'Emerging',
        self::ESTABLISHED => 'Established',
        self::DECLINING   => 'Declining',
        self::SEASONAL    => 'Seasonal',
        self::END_OF_LIFE => 'End of life',
    ];

    public const NEW_DAYS            = 91;    // 13 weeks
    public const EMERGING_DAYS       = 182;   // 26 weeks
    public const END_OF_LIFE_DAYS    = 56;    // 8 weeks without a sale or stock
    public const DECLINE_RATIO       = 0.70;
    public const DECLINE_STORE_SHARE = 0.60;
    public const GROWTH_RATIO        = 1.10;
    public const SEASONAL_PEAK_SHARE = 0.60;
    public const DATA_START_MARGIN   = 28;    // a first sale this close to the data's start says nothing about age

    /** Product statuses that mean the product is finished. */
    public const INACTIVE_STATUSES = ['discontinued', 'delisted', 'inactive', 'obsolete', 'blocked'];

    /**
     * Rebuild the tenant's lifecycle rows as of a date.
     *
     * @return array<string,int> chain-wide count per state, plus 'store_rows'
     */
    public function rebuild(int $tenantId, string $asOf): array
    {
        $d = fn (int $days) => Carbon::parse($asOf)->subDays($days)->toDateString();
        $inactive = "'" . implode("','", self::INACTIVE_STATUSES) . "'";
        $margin = self::DATA_START_MARGIN;

        DB::transaction(function () use ($tenantId, $asOf, $d, $inactive, $margin) {
            DB::statement('DROP TABLE IF EXISTS tmp_lc_pos');
            DB::statement(
                'CREATE TEMP TABLE tmp_lc_pos ON COMMIT DROP AS
                 SELECT store_id, sku, MIN(date) AS first_sale, MAX(date) AS last_sale,
                        SUM(units_sold) FILTER (WHERE date >  CAST(? AS date))                              AS r13,
                        SUM(units_sold) FILTER (WHERE date >  CAST(? AS date) AND date <= CAST(? AS date))  AS p13,
                        SUM(units_sold) FILTER (WHERE date >  CAST(? AS date))                              AS r6,
                        SUM(units_sold) FILTER (WHERE date >  CAST(? AS date) AND date <= CAST(? AS date))  AS p6
                   FROM sales_daily
                  WHERE tenant_id = ? AND store_id IS NOT NULL AND units_sold > 0 AND date <= CAST(? AS date)
               GROUP BY store_id, sku',
                [$d(91), $d(182), $d(91), $d(42), $d(84), $d(42), $tenantId, $asOf],
            );
            DB::statement('CREATE INDEX ON tmp_lc_pos (sku)');

            // Recent stock only: enough to know whether a product is still on a shelf anywhere.
            DB::statement('DROP TABLE IF EXISTS tmp_lc_stock');
            DB::statement(
                'CREATE TEMP TABLE tmp_lc_stock ON COMMIT DROP AS
                 SELECT store_id, sku, MAX(as_of_date) AS last_stock
                   FROM inventory_levels
                  WHERE tenant_id = ? AND store_id IS NOT NULL AND on_hand_qty > 0
                    AND as_of_date > CAST(? AS date) AND as_of_date <= CAST(? AS date)
               GROUP BY store_id, sku',
                [$tenantId, $d(120), $asOf],
            );

            // Seasonality needs a year: the busiest 13 consecutive weeks of the last 52.
            $hasYear = (bool) DB::selectOne('SELECT MIN(first_sale) <= CAST(? AS date) AS y FROM tmp_lc_pos', [$d(364)])?->y;
            DB::statement('DROP TABLE IF EXISTS tmp_lc_peak');
            DB::statement('CREATE TEMP TABLE tmp_lc_peak (sku varchar(100) PRIMARY KEY, peak_share numeric) ON COMMIT DROP');
            if ($hasYear) {
                DB::statement(
                    'INSERT INTO tmp_lc_peak (sku, peak_share)
                     SELECT sku, MAX(rolling) / NULLIF(MAX(total), 0)
                       FROM (SELECT sku,
                                    SUM(u) OVER (PARTITION BY sku ORDER BY w RANGE BETWEEN 12 PRECEDING AND CURRENT ROW) AS rolling,
                                    SUM(u) OVER (PARTITION BY sku) AS total
                               FROM (SELECT sku, FLOOR((date - CAST(? AS date)) / 7)::int AS w, SUM(units_sold) AS u
                                       FROM sales_daily
                                      WHERE tenant_id = ? AND store_id IS NOT NULL AND units_sold > 0
                                        AND date > CAST(? AS date) AND date <= CAST(? AS date)
                                   GROUP BY 1, 2) weekly) r
                   GROUP BY sku',
                    [$d(364), $tenantId, $d(364), $asOf],
                );
            }

            DB::table('product_lifecycles')->where('tenant_id', $tenantId)->delete();

            // Chain-wide rows.
            DB::statement(
                "INSERT INTO product_lifecycles
                    (tenant_id, store_id, sku, state, reason, first_sale, last_activity, age_days, recent_units, prior_units, peak_share, as_of_date, created_at, updated_at)
                 SELECT ?, NULL, c.sku, s.state, s.reason, c.first_sale, c.last_activity, c.age, c.r13, c.p13, c.peak, CAST(? AS date), NOW(), NOW()
                   FROM (SELECT p.sku,
                                MIN(p.first_sale) AS first_sale,
                                GREATEST(MAX(p.last_sale), MAX(st.last_stock)) AS last_activity,
                                CAST(? AS date) - MIN(p.first_sale) AS age,
                                COALESCE(SUM(p.r13), 0) AS r13, COALESCE(SUM(p.p13), 0) AS p13,
                                COALESCE(SUM(p.r6), 0) AS r6, COALESCE(SUM(p.p6), 0) AS p6,
                                COUNT(*) FILTER (WHERE p.p13 > 0 AND COALESCE(p.r13, 0) < ? * p.p13)::numeric
                                    / NULLIF(COUNT(*) FILTER (WHERE p.p13 > 0), 0) AS falling_share,
                                MAX(pk.peak_share) AS peak,
                                MAX(LOWER(TRIM(COALESCE(pr.status, '')))) AS status,
                                MAX(TRIM(COALESCE(pr.season, ''))) AS season
                           FROM tmp_lc_pos p
                      LEFT JOIN tmp_lc_stock st ON st.store_id = p.store_id AND st.sku = p.sku
                      LEFT JOIN tmp_lc_peak pk ON pk.sku = p.sku
                      LEFT JOIN products pr ON pr.tenant_id = ? AND pr.sku = p.sku
                       GROUP BY p.sku) c
                 CROSS JOIN (SELECT MIN(first_sale) + {$margin} AS known FROM tmp_lc_pos) z
                 CROSS JOIN LATERAL (SELECT CASE
                        WHEN c.status IN ({$inactive})                  THEN 'end_of_life'
                        WHEN c.last_activity < CAST(? AS date)           THEN 'end_of_life'
                        WHEN c.age < ? AND c.first_sale > z.known        THEN 'new'
                        WHEN c.season <> '' OR c.peak >= ?               THEN 'seasonal'
                        WHEN c.age >= ? AND c.p13 > 0 AND c.r13 < ? * c.p13 AND COALESCE(c.falling_share, 0) >= ? THEN 'declining'
                        WHEN c.age < ? AND c.first_sale > z.known AND c.r6 > ? * c.p6 THEN 'emerging'
                        ELSE 'established' END AS state,
                     CASE
                        WHEN c.status IN ({$inactive})                  THEN 'Status on the product file: ' || c.status
                        WHEN c.last_activity < CAST(? AS date)           THEN 'No sale or stock anywhere for 8 weeks'
                        WHEN c.age < ? AND c.first_sale > z.known        THEN 'First sold ' || c.age || ' days ago'
                        WHEN c.season <> ''                              THEN 'Season on the product file: ' || c.season
                        WHEN c.peak >= ?                                 THEN ROUND(c.peak * 100) || '% of a year''s units in its busiest 13 weeks'
                        WHEN c.age >= ? AND c.p13 > 0 AND c.r13 < ? * c.p13 AND COALESCE(c.falling_share, 0) >= ?
                                                                         THEN 'Last 13 weeks ' || ROUND(100 * c.r13 / c.p13) || '% of the 13 before, falling in ' || ROUND(100 * c.falling_share) || '% of stores'
                        WHEN c.age < ? AND c.first_sale > z.known AND c.r6 > ? * c.p6 THEN 'Still growing: ' || c.age || ' days old'
                        ELSE NULL END AS reason) s",
                [
                    $tenantId, $asOf, $asOf, self::DECLINE_RATIO, $tenantId,
                    $d(self::END_OF_LIFE_DAYS), self::NEW_DAYS, self::SEASONAL_PEAK_SHARE,
                    self::EMERGING_DAYS, self::DECLINE_RATIO, self::DECLINE_STORE_SHARE, self::EMERGING_DAYS, self::GROWTH_RATIO,
                    $d(self::END_OF_LIFE_DAYS), self::NEW_DAYS, self::SEASONAL_PEAK_SHARE,
                    self::EMERGING_DAYS, self::DECLINE_RATIO, self::DECLINE_STORE_SHARE, self::EMERGING_DAYS, self::GROWTH_RATIO,
                ],
            );

            // Store rows: new / emerging / declining here; chain-wide end of life and seasonality carry down.
            DB::statement(
                "INSERT INTO product_lifecycles
                    (tenant_id, store_id, sku, state, reason, first_sale, last_activity, age_days, recent_units, prior_units, peak_share, as_of_date, created_at, updated_at)
                 SELECT ?, p.store_id, p.sku, s.state, s.reason, p.first_sale, GREATEST(p.last_sale, st.last_stock),
                        CAST(? AS date) - p.first_sale, COALESCE(p.r13, 0), COALESCE(p.p13, 0), NULL, CAST(? AS date), NOW(), NOW()
                   FROM tmp_lc_pos p
              LEFT JOIN tmp_lc_stock st ON st.store_id = p.store_id AND st.sku = p.sku
                   JOIN product_lifecycles c ON c.tenant_id = ? AND c.store_id IS NULL AND c.sku = p.sku
                   JOIN (SELECT store_id, MIN(first_sale) + {$margin} AS known FROM tmp_lc_pos GROUP BY store_id) z ON z.store_id = p.store_id
                 CROSS JOIN LATERAL (SELECT CASE
                        WHEN c.state = 'end_of_life'                                           THEN 'end_of_life'
                        WHEN CAST(? AS date) - p.first_sale < ? AND p.first_sale > z.known     THEN 'new'
                        WHEN c.state = 'seasonal'                                              THEN 'seasonal'
                        WHEN CAST(? AS date) - p.first_sale >= ? AND COALESCE(p.p13, 0) > 0 AND COALESCE(p.r13, 0) < ? * p.p13 THEN 'declining'
                        WHEN CAST(? AS date) - p.first_sale < ? AND p.first_sale > z.known AND COALESCE(p.r6, 0) > ? * COALESCE(p.p6, 0) THEN 'emerging'
                        ELSE 'established' END AS state,
                     CASE
                        WHEN c.state IN ('end_of_life', 'seasonal')                            THEN c.reason
                        WHEN CAST(? AS date) - p.first_sale < ? AND p.first_sale > z.known     THEN 'First sold here ' || (CAST(? AS date) - p.first_sale) || ' days ago'
                        ELSE NULL END AS reason) s",
                [
                    $tenantId, $asOf, $asOf, $tenantId,
                    $asOf, self::NEW_DAYS, $asOf, self::EMERGING_DAYS, self::DECLINE_RATIO, $asOf, self::EMERGING_DAYS, self::GROWTH_RATIO,
                    $asOf, self::NEW_DAYS, $asOf,
                ],
            );
        });

        $out = array_fill_keys(array_keys(self::STATES), 0);
        foreach (DB::select('SELECT state, COUNT(*) AS n FROM product_lifecycles WHERE tenant_id = ? AND store_id IS NULL GROUP BY state', [$tenantId]) as $r) {
            $out[$r->state] = (int) $r->n;
        }
        $out['store_rows'] = (int) DB::table('product_lifecycles')->where('tenant_id', $tenantId)->whereNotNull('store_id')->count();

        return $out;
    }

    /**
     * Chain-wide state per product.
     *
     * @return array<string,array{state:string, reason:?string}> sku => state
     */
    public function chain(int $tenantId): array
    {
        $out = [];
        foreach (DB::table('product_lifecycles')->where('tenant_id', $tenantId)->whereNull('store_id')
            ->get(['sku', 'state', 'reason']) as $r) {
            $out[(string) $r->sku] = ['state' => (string) $r->state, 'reason' => $r->reason];
        }

        return $out;
    }

    /** One product's state at a store, falling back to its chain-wide state; null when unknown. */
    public function at(int $tenantId, ?int $storeId, string $sku): ?array
    {
        $rows = DB::table('product_lifecycles')->where('tenant_id', $tenantId)->where('sku', $sku)
            ->where(fn ($q) => $q->whereNull('store_id')->when($storeId, fn ($q) => $q->orWhere('store_id', $storeId)))
            ->get(['store_id', 'state', 'reason']);
        $row = $rows->firstWhere('store_id', $storeId) ?? $rows->firstWhere('store_id', null);

        return $row ? ['state' => (string) $row->state, 'reason' => $row->reason] : null;
    }
}
