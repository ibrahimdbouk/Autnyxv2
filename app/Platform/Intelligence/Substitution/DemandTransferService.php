<?php

namespace App\Platform\Intelligence\Substitution;

use App\Platform\Intelligence\Promotions\PromotionCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Platform intelligence: transferable demand, OBSERVED.
 *
 * When product A is not on the shelf, its buyers either switch to another
 * product in the store (transferred demand) or buy nothing there (lost demand).
 * This service measures, for a pool of comparable stores, the share of A's
 * demand that moves to each product B of the same kind, from two kinds of
 * evidence, strongest first:
 *
 *  1. Stockout days. A day A had no stock and no sale (a full-day stockout)
 *     while B was in stock: B's units above its normal level at that store,
 *     divided by A's normal units. Both are divided by that day's index from
 *     the rest of the category (its other subcategories that day ÷ their
 *     average day; 1 when the category has no other subcategory), so a busy day
 *     is not counted as switching and the switching itself does not move it. Promotion days of either product are
 *     left out, and so are rates from fewer than 14 clean in-stock days.
 *
 *  2. Range changes. A launched at, or dropped from, a store while B stayed:
 *     B's units in the 28 days after against the 28 before, compared with the
 *     same change at pool stores where A did not change (difference in
 *     differences), divided by A's own units (after a launch, before an exit).
 *
 * Both are combined by inverse variance; shares are never negative and the
 * shares out of one product add up to 100% at most. Stored with the stores,
 * store-days and events behind them, the period and the model version. This is
 * correlation read from natural experiments, not proof: consumers say
 * "estimated from", never "caused". Pairs are only formed inside a product's
 * subcategory (its category when it has none), and kinds of more than 150
 * products are skipped rather than exploding into pairs.
 */
final class DemandTransferService
{
    public const VERSION = 'dt-2026.10.1';

    /** Evidence needed before an observation is stored at all. */
    public const MIN_STORE_DAYS  = 10;
    public const MIN_STORES      = 2;
    public const MIN_BASE_UNITS  = 5.0;
    public const MIN_CLEAN_DAYS  = 14;
    public const MAX_KIND_SIZE   = 150;
    public const EVENT_DAYS      = 28;
    public const MAX_EVENTS      = 300;   // most recent range changes read per batch
    public const BATCH_SKUS      = 400;   // products per query, whole categories

    /**
     * Re-measure one pool of stores over a window.
     *
     * @param  array<int,int>  $storeIds
     * @return array{pairs:int, from_stockouts:int, from_range_changes:int, batches:int}
     */
    public function rebuild(int $tenantId, string $pool, array $storeIds, string $from, string $to, ?PromotionCalendar $promos = null): array
    {
        $pool = mb_substr($pool, 0, 64);
        DB::table('demand_transfers')->where('tenant_id', $tenantId)->where('pool', $pool)->delete();
        $storeIds = array_values(array_unique(array_map('intval', $storeIds)));
        if (count($storeIds) < self::MIN_STORES) {
            return ['pairs' => 0, 'from_stockouts' => 0, 'from_range_changes' => 0, 'batches' => 0];
        }

        $in = implode(',', $storeIds);
        $starts = $this->storeStarts($tenantId, $storeIds);
        $stats = ['pairs' => 0, 'from_stockouts' => 0, 'from_range_changes' => 0, 'batches' => 0];

        foreach ($this->batches($tenantId, $in) as $categories) {
            $stats['batches']++;
            $skus = DB::table('products')->where('tenant_id', $tenantId)
                ->whereIn(DB::raw('TRIM(category)'), $categories)->pluck('sku')->map(fn ($s) => (string) $s)->all();
            $promoJson = $promos && ! $promos->isEmpty() ? $promos->json($skus, $from, $to) : '[]';

            // One transaction per batch: its temp tables go when it commits.
            [$so, $rc] = DB::transaction(function () use ($tenantId, $in, $categories, $from, $to, $promoJson, $starts) {
                $this->stageProducts($tenantId, $categories);

                return [
                    $this->fromStockouts($tenantId, $in, $from, $to, $promoJson),
                    $this->fromRangeChanges($tenantId, $in, $to, $starts),
                ];
            });
            $stats['from_stockouts']     += count($so);
            $stats['from_range_changes'] += count($rc);

            $stats['pairs'] += $this->save($tenantId, $pool, $this->combine($so, $rc), $from, $to);
            unset($so, $rc);
        }

        return $stats;
    }

    /**
     * Observed transfers out of these products, for a pool.
     *
     * @param  array<int,string>  $fromSkus
     * @return array<string,array<string,array<string,mixed>>> from_sku => to_sku => row
     */
    public function observed(int $tenantId, string $pool, array $fromSkus): array
    {
        if ($fromSkus === []) {
            return [];
        }
        $pool = mb_substr($pool, 0, 64);
        $out = [];
        foreach (array_chunk($fromSkus, 1000) as $chunk) {
            foreach (DB::table('demand_transfers')->where('tenant_id', $tenantId)->where('pool', $pool)
                ->whereIn('from_sku', $chunk)
                ->get(['from_sku', 'to_sku', 'basis', 'share', 'share_se', 'stores', 'store_days', 'events', 'base_units', 'period_from', 'period_to', 'model_version']) as $r) {
                $out[(string) $r->from_sku][(string) $r->to_sku] = [
                    'basis'      => (string) $r->basis,
                    'share'      => (float) $r->share,
                    'se'         => (float) $r->share_se,
                    'stores'     => (int) $r->stores,
                    'store_days' => (int) $r->store_days,
                    'events'     => (int) $r->events,
                    'base_units' => (float) $r->base_units,
                    'period'     => [(string) $r->period_from, (string) $r->period_to],
                    'version'    => (string) $r->model_version,
                ];
            }
        }

        return $out;
    }

    // ── The batch's products ──────────────────────────────────────────────────

    /**
     * The batch's products and their kind (subcategory, or the category when
     * there is none), in a temp table with statistics: every later step joins on
     * it, and a planner that guesses 40 rows for 400 picks nested loops that never end.
     */
    private function stageProducts(int $tenantId, array $categories): void
    {
        $catMarks = implode(',', array_fill(0, count($categories), '?'));
        DB::statement('DROP TABLE IF EXISTS tmp_dt_prod');
        DB::statement(
            "CREATE TEMP TABLE tmp_dt_prod ON COMMIT DROP AS
             SELECT sku, cat, kind FROM (
                 SELECT sku, TRIM(category) AS cat, COALESCE(NULLIF(TRIM(subcategory), ''), '') AS kind,
                        COUNT(*) OVER (PARTITION BY TRIM(category), COALESCE(NULLIF(TRIM(subcategory), ''), '')) AS n
                   FROM products WHERE tenant_id = ? AND TRIM(category) IN ({$catMarks})
             ) x WHERE n BETWEEN 2 AND ?",
            [$tenantId, ...$categories, self::MAX_KIND_SIZE],
        );
        DB::statement('CREATE INDEX ON tmp_dt_prod (sku)');
        DB::statement('ANALYZE tmp_dt_prod');
    }

    // ── Evidence 1: stockout days ─────────────────────────────────────────────

    /** @return array<string,array<string,array{share:float,se:float,stores:int,store_days:int,base:float}>> */
    private function fromStockouts(int $tenantId, string $in, string $from, string $to, string $promoJson): array
    {
        // Every (store, product, day) with a stock reading or a sale, with its promotion flag.
        DB::statement('DROP TABLE IF EXISTS tmp_dt_day');
        DB::statement(
            "CREATE TEMP TABLE tmp_dt_day ON COMMIT DROP AS
             WITH inv AS (SELECT store_id AS st, sku, as_of_date AS d, SUM(on_hand_qty) AS qty
                            FROM inventory_levels
                           WHERE tenant_id = ? AND store_id IN ({$in}) AND as_of_date BETWEEN ? AND ?
                             AND sku IN (SELECT sku FROM tmp_dt_prod)
                        GROUP BY 1, 2, 3),
                  sal AS (SELECT store_id AS st, sku, date AS d, SUM(units_sold) AS u
                            FROM sales_daily
                           WHERE tenant_id = ? AND store_id IN ({$in}) AND date BETWEEN ? AND ?
                             AND sku IN (SELECT sku FROM tmp_dt_prod)
                        GROUP BY 1, 2, 3),
                  promo AS MATERIALIZED (SELECT * FROM jsonb_to_recordset(CAST(? AS jsonb)) AS x(sku text, st bigint, f date, t date))
             SELECT x.st, x.sku, p.cat, p.kind, x.d, x.qty, x.u,
                    EXISTS (SELECT 1 FROM promo pr WHERE pr.sku = x.sku AND (pr.st IS NULL OR pr.st = x.st) AND x.d BETWEEN pr.f AND pr.t) AS onpromo
               FROM (SELECT COALESCE(i.st, s.st) AS st, COALESCE(i.sku, s.sku) AS sku, COALESCE(i.d, s.d) AS d, i.qty, COALESCE(s.u, 0) AS u
                       FROM inv i FULL JOIN sal s ON s.st = i.st AND s.sku = i.sku AND s.d = i.d) x
               JOIN tmp_dt_prod p ON p.sku = x.sku",
            [$tenantId, $from, $to, $tenantId, $from, $to, $promoJson],
        );
        DB::statement('ANALYZE tmp_dt_day');

        // The day index from the rest of the category (its other subcategories): not touched by switching
        // inside this subcategory, so a busy or quiet day shows there too; 1 when there is no other subcategory.
        // Every join from here on is between the batch's own temp tables: hash joins, never nested loops
        // (the planner's row guesses for grouped CTEs are far too low, and a nested loop then never finishes).
        DB::statement('SET LOCAL enable_nestloop = off');
        DB::statement('DROP TABLE IF EXISTS tmp_dt_y');
        DB::statement(
            'CREATE TEMP TABLE tmp_dt_y ON COMMIT DROP AS
             WITH k AS (SELECT st, cat, kind, d, SUM(u) AS ku FROM tmp_dt_day GROUP BY 1, 2, 3, 4),
                  c AS (SELECT st, cat, d, SUM(ku) AS cu FROM k GROUP BY 1, 2, 3),
                  r AS (SELECT k.st, k.cat, k.kind, k.d, c.cu - k.ku AS r FROM k JOIN c ON c.st = k.st AND c.cat = k.cat AND c.d = k.d),
                  ra AS (SELECT st, cat, kind, AVG(r) AS a FROM r GROUP BY 1, 2, 3)
             SELECT t.st, t.sku, t.cat, t.kind, t.d, t.u,
                    (t.u > 0 OR COALESCE(t.qty, 0) > 0)              AS ins,
                    (t.qty IS NOT NULL AND t.qty <= 0 AND t.u = 0)  AS outd,
                    t.onpromo,
                    CASE WHEN ra.a > 0 AND r.r > 0 THEN r.r / ra.a ELSE 1 END AS ix
               FROM tmp_dt_day t
               JOIN r  ON r.st = t.st AND r.cat = t.cat AND r.kind = t.kind AND r.d = t.d
               JOIN ra ON ra.st = t.st AND ra.cat = t.cat AND ra.kind = t.kind',
        );
        DB::statement('ANALYZE tmp_dt_y');

        $rows = DB::select(
            'WITH own AS (SELECT st, sku, COUNT(*) AS n_in, SUM(u / ix) AS s_in FROM tmp_dt_y WHERE ins AND NOT onpromo GROUP BY st, sku),
                  pairs AS (
                      SELECT a.st, a.sku AS fa, b.sku AS fb, COUNT(*) AS n, SUM(b.u / b.ix) AS sb, SUM((b.u / b.ix) ^ 2) AS sb2
                        FROM tmp_dt_y a
                        JOIN tmp_dt_y b ON b.st = a.st AND b.d = a.d AND b.cat = a.cat AND b.kind = a.kind AND b.sku <> a.sku
                       WHERE a.outd AND NOT a.onpromo AND b.ins AND NOT b.onpromo
                    GROUP BY 1, 2, 3
                  ),
                  per AS (
                      SELECT pa.fa, pa.fb, pa.st, pa.n, pa.sb, pa.sb2,
                             oa.s_in / oa.n_in                    AS ma,
                             (ob.s_in - pa.sb) / (ob.n_in - pa.n) AS mb
                        FROM pairs pa
                        JOIN own oa ON oa.st = pa.st AND oa.sku = pa.fa
                        JOIN own ob ON ob.st = pa.st AND ob.sku = pa.fb
                       WHERE oa.n_in >= ? AND ob.n_in - pa.n >= ?
                  )
             SELECT fa, fb, COUNT(*) AS stores, SUM(n) AS store_days,
                    SUM(sb - n * mb) AS extra, SUM(n * ma) AS base,
                    SUM(GREATEST(sb2 - sb * sb / n, 0)) AS ss
               FROM per WHERE ma > 0
           GROUP BY fa, fb',
            [self::MIN_CLEAN_DAYS, self::MIN_CLEAN_DAYS],
        );
        DB::statement('SET LOCAL enable_nestloop = on');

        $out = [];
        foreach ($rows as $r) {
            $base = (float) $r->base;
            if ((int) $r->store_days < self::MIN_STORE_DAYS || (int) $r->stores < self::MIN_STORES || $base < self::MIN_BASE_UNITS) {
                continue;
            }
            $out[(string) $r->fa][(string) $r->fb] = [
                'share'      => max(0.0, (float) $r->extra / $base),
                'se'         => max(0.02, sqrt(max(0.0, (float) $r->ss)) / $base),
                'stores'     => (int) $r->stores,
                'store_days' => (int) $r->store_days,
                'base'       => $base,
            ];
        }

        return $out;
    }

    // ── Evidence 2: range changes ─────────────────────────────────────────────

    /** @return array<string,array<string,array{share:float,se:float,events:int,base:float,stores:int}>> */
    private function fromRangeChanges(int $tenantId, string $in, string $asOf, string $starts): array
    {
        $w = self::EVENT_DAYS;

        // When each product first and last sold at each store, over the whole history.
        DB::statement('DROP TABLE IF EXISTS tmp_dt_act');
        DB::statement(
            "CREATE TEMP TABLE tmp_dt_act ON COMMIT DROP AS
             SELECT store_id AS st, sku, MIN(date) AS f, MAX(date) AS l
               FROM sales_daily
              WHERE tenant_id = ? AND store_id IN ({$in}) AND units_sold > 0 AND date <= CAST(? AS date)
                AND sku IN (SELECT sku FROM tmp_dt_prod)
           GROUP BY 1, 2",
            [$tenantId, $asOf],
        );
        DB::statement('CREATE INDEX ON tmp_dt_act (sku, st)');
        DB::statement('ANALYZE tmp_dt_act');

        // Launches (well after the store's data starts) and exits (no sale or stock for 8 weeks since), newest first.
        DB::statement('DROP TABLE IF EXISTS tmp_dt_ev');
        DB::statement(
            "CREATE TEMP TABLE tmp_dt_ev ON COMMIT DROP AS
             WITH starts AS MATERIALIZED (SELECT * FROM jsonb_to_recordset(CAST(? AS jsonb)) AS x(st bigint, s0 date)),
                  stk AS (SELECT store_id AS st, sku, MAX(as_of_date) AS ls
                            FROM inventory_levels
                           WHERE tenant_id = ? AND store_id IN ({$in}) AND on_hand_qty > 0 AND as_of_date <= CAST(? AS date)
                             AND sku IN (SELECT sku FROM tmp_dt_act WHERE l <= CAST(? AS date) - 57)
                        GROUP BY 1, 2)
             SELECT * FROM (
                 SELECT a.st, a.sku, a.f AS d, 'launch' AS kind
                   FROM tmp_dt_act a JOIN starts s ON s.st = a.st
                  WHERE a.f >= s.s0 + {$w} AND a.f <= CAST(? AS date) - {$w}
              UNION ALL
                 SELECT a.st, a.sku, a.l + 1 AS d, 'exit' AS kind
                   FROM tmp_dt_act a LEFT JOIN stk k ON k.st = a.st AND k.sku = a.sku
                  WHERE a.l <= CAST(? AS date) - 57 AND a.f <= a.l - {$w} AND COALESCE(k.ls, a.l) <= a.l + 7
             ) e ORDER BY d DESC LIMIT ?",
            [$starts, $tenantId, $asOf, $asOf, $asOf, $asOf, self::MAX_EVENTS],
        );
        if ((int) DB::selectOne('SELECT COUNT(*) AS n FROM tmp_dt_ev')->n === 0) {
            return [];
        }
        DB::statement('ANALYZE tmp_dt_ev');

        // Each event × each product of the same kind that stayed on that shelf across the event.
        DB::statement('DROP TABLE IF EXISTS tmp_dt_pb');
        DB::statement(
            "CREATE TEMP TABLE tmp_dt_pb ON COMMIT DROP AS
             SELECT e.st, e.sku AS fa, e.d, e.kind, pb.sku AS fb
               FROM tmp_dt_ev e
               JOIN tmp_dt_prod pa ON pa.sku = e.sku
               JOIN tmp_dt_prod pb ON pb.cat = pa.cat AND pb.kind = pa.kind AND pb.sku <> e.sku
               JOIN tmp_dt_act ab  ON ab.st = e.st AND ab.sku = pb.sku AND ab.f <= e.d - {$w} AND ab.l >= e.d + {$w} - 1",
        );
        DB::statement('ANALYZE tmp_dt_pb');

        $rows = DB::select(
            "WITH tr AS (
                  SELECT pb.st, pb.fa, pb.fb, pb.d, pb.kind,
                         COALESCE(SUM(sd.units_sold) FILTER (WHERE sd.date <  pb.d), 0) AS bef,
                         COALESCE(SUM(sd.units_sold) FILTER (WHERE sd.date >= pb.d), 0) AS aft
                    FROM tmp_dt_pb pb LEFT JOIN sales_daily sd
                      ON sd.tenant_id = ? AND sd.store_id = pb.st AND sd.sku = pb.fb AND sd.date BETWEEN pb.d - {$w} AND pb.d + {$w} - 1
                GROUP BY 1, 2, 3, 4, 5
             ),
             ua AS (
                  SELECT e.st, e.sku AS fa, e.d,
                         COALESCE(SUM(sd.units_sold) FILTER (WHERE (e.kind = 'launch' AND sd.date >= e.d) OR (e.kind = 'exit' AND sd.date < e.d)), 0) AS u
                    FROM tmp_dt_ev e LEFT JOIN sales_daily sd
                      ON sd.tenant_id = ? AND sd.store_id = e.st AND sd.sku = e.sku AND sd.date BETWEEN e.d - {$w} AND e.d + {$w} - 1
                GROUP BY 1, 2, 3
             ),
             ctl AS (
                  SELECT pb.st, pb.fa, pb.fb, pb.d,
                         SUM(sd.units_sold) FILTER (WHERE sd.date <  pb.d) AS cb,
                         SUM(sd.units_sold) FILTER (WHERE sd.date >= pb.d) AS ca
                    FROM tmp_dt_pb pb
                    JOIN tmp_dt_act ac ON ac.sku = pb.fb AND ac.st <> pb.st AND ac.f <= pb.d - {$w} AND ac.l >= pb.d + {$w} - 1
                    LEFT JOIN tmp_dt_act aa ON aa.st = ac.st AND aa.sku = pb.fa
                    JOIN sales_daily sd ON sd.tenant_id = ? AND sd.store_id = ac.st AND sd.sku = pb.fb
                                       AND sd.date BETWEEN pb.d - {$w} AND pb.d + {$w} - 1
                   WHERE aa.sku IS NULL OR NOT (aa.f BETWEEN pb.d - {$w} AND pb.d + {$w} OR aa.l BETWEEN pb.d - {$w} AND pb.d + {$w})
                GROUP BY 1, 2, 3, 4
             ),
             one AS (
                  SELECT tr.fa, tr.fb, tr.st, ua.u,
                         CASE WHEN tr.kind = 'launch'
                              THEN tr.bef * (COALESCE(ctl.ca, 0) / ctl.cb) - tr.aft
                              ELSE tr.aft - tr.bef * (COALESCE(ctl.ca, 0) / ctl.cb) END AS gain
                    FROM tr
                    JOIN ctl ON ctl.st = tr.st AND ctl.fa = tr.fa AND ctl.fb = tr.fb AND ctl.d = tr.d
                    JOIN ua  ON ua.st = tr.st AND ua.fa = tr.fa AND ua.d = tr.d
                   WHERE ctl.cb > 0 AND ua.u > 0
             )
             SELECT fa, fb, COUNT(*) AS events, COUNT(DISTINCT st) AS stores, SUM(gain) AS gain, SUM(u) AS base,
                    STDDEV_SAMP(gain / u) AS sd
               FROM one
           GROUP BY fa, fb",
            [$tenantId, $tenantId, $tenantId],
        );

        $out = [];
        foreach ($rows as $r) {
            $base = (float) $r->base;
            if ($base < self::MIN_BASE_UNITS) {
                continue;
            }
            $n = (int) $r->events;
            $out[(string) $r->fa][(string) $r->fb] = [
                'share'  => max(0.0, (float) $r->gain / $base),
                'se'     => $n >= 2 && $r->sd !== null ? max(0.03, (float) $r->sd / sqrt($n)) : 0.30,
                'events' => $n,
                'stores' => (int) $r->stores,
                'base'   => $base,
            ];
        }

        return $out;
    }

    // ── Combining and saving ──────────────────────────────────────────────────

    /**
     * Inverse-variance combination of the two kinds of evidence, then the shares
     * out of each product capped at 100% in total.
     *
     * @return array<string,array<string,array<string,mixed>>>
     */
    private function combine(array $so, array $rc): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($so), array_keys($rc))) as $a) {
            $a = (string) $a;
            foreach (array_unique(array_merge(array_keys($so[$a] ?? []), array_keys($rc[$a] ?? []))) as $b) {
                $b = (string) $b;
                $x = $so[$a][$b] ?? null;
                $y = $rc[$a][$b] ?? null;
                $parts = array_values(array_filter([$x, $y]));
                $wSum = array_sum(array_map(fn ($p) => 1 / ($p['se'] ** 2), $parts));
                $share = array_sum(array_map(fn ($p) => $p['share'] / ($p['se'] ** 2), $parts)) / $wSum;
                $out[$a][$b] = [
                    'basis'      => $x && $y ? 'both' : ($x ? 'stockouts' : 'range_changes'),
                    'share'      => min(1.0, max(0.0, $share)),
                    'se'         => 1 / sqrt($wSum),
                    'stores'     => max($x['stores'] ?? 0, $y['stores'] ?? 0),
                    'store_days' => (int) ($x['store_days'] ?? 0),
                    'events'     => (int) ($y['events'] ?? 0),
                    'base'       => (float) ($x['base'] ?? 0) + (float) ($y['base'] ?? 0),
                ];
            }
            $total = array_sum(array_column($out[$a], 'share'));
            if ($total > 1.0) {
                foreach ($out[$a] as $b => $row) {
                    $out[$a][$b]['share'] = $row['share'] / $total;
                }
            }
        }

        return $out;
    }

    private function save(int $tenantId, string $pool, array $pairs, string $from, string $to): int
    {
        $now = now();
        $rows = [];
        foreach ($pairs as $a => $bs) {
            foreach ($bs as $b => $p) {
                $rows[] = [
                    'tenant_id' => $tenantId, 'pool' => $pool, 'from_sku' => (string) $a, 'to_sku' => (string) $b,
                    'basis' => $p['basis'], 'share' => round($p['share'], 4), 'share_se' => round(min(9.9, $p['se']), 4),
                    'stores' => $p['stores'], 'store_days' => $p['store_days'], 'events' => $p['events'],
                    'base_units' => round($p['base'], 4), 'period_from' => $from, 'period_to' => $to,
                    'model_version' => self::VERSION, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('demand_transfers')->insert($chunk);
        }

        return count($rows);
    }

    /** First sale per store — a launch has to come well after a store's data starts. */
    private function storeStarts(int $tenantId, array $storeIds): string
    {
        $out = [];
        foreach ($storeIds as $id) {
            $d = DB::table('sales_daily')->where('tenant_id', $tenantId)->where('store_id', $id)->min('date');
            if ($d) {
                $out[] = ['st' => $id, 's0' => Carbon::parse($d)->toDateString()];
            }
        }

        return json_encode($out) ?: '[]';
    }

    /**
     * Whole categories grouped into batches of about BATCH_SKUS products.
     *
     * @return array<int,array<int,string>>
     */
    private function batches(int $tenantId, string $in): array
    {
        $cats = DB::select(
            "SELECT TRIM(p.category) AS cat, COUNT(*) AS n
               FROM products p
              WHERE p.tenant_id = ? AND p.category IS NOT NULL AND TRIM(p.category) <> ''
                AND EXISTS (SELECT 1 FROM sales_daily sd WHERE sd.tenant_id = p.tenant_id AND sd.sku = p.sku AND sd.store_id IN ({$in}))
           GROUP BY 1 ORDER BY 1",
            [$tenantId],
        );
        $batches = [];
        $current = [];
        $size = 0;
        foreach ($cats as $c) {
            if ($current !== [] && $size + (int) $c->n > self::BATCH_SKUS) {
                $batches[] = $current;
                $current = [];
                $size = 0;
            }
            $current[] = (string) $c->cat;
            $size += (int) $c->n;
        }
        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }
}
