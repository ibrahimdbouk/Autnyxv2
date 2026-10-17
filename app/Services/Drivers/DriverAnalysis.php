<?php

namespace App\Services\Drivers;

use App\Models\RetailEvent;
use App\Platform\Intelligence\Promotions\PromotionCalendar;
use App\Services\Calendar\RetailCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Root Cause — what moved sales, and why (deterministic; no model).
 *
 * The revenue change between a period and a comparison period (the
 * comparison scaled to the period's length, so "change" is what the period
 * made above or below the comparison's pace) is split two ways, each adding
 * up exactly to the change:
 *
 * WHAT moved (index decomposition, LMDI for the multiplicative part):
 *   traffic     receipts per day (visitors and conversion when door counts
 *               cover the scope)
 *   penetration receipts that contain the category ÷ store receipts
 *               (category scope only)
 *   basket      units per receipt
 *   mix         the shift between products, stores and ranges at different
 *               prices (volume at the comparison's prices, less units × the
 *               comparison's average price)
 *   price       each position's own price change, at the period's volume
 *
 *   Without receipt ids, traffic and basket are one figure: volume.
 *
 * WHY the non-price part moved (attribution, each measured from data, valued
 * at the position's comparison price):
 *   availability  units lost on out-of-stock days, period vs comparison
 *                 (in-stock selling rate × share of days out)
 *   promotions    incremental promotion units (units on promotion days above
 *                 the position's normal rate), period vs comparison
 *   events        the retail calendar: an event covering one period more than
 *                 the other, × the department's lift the last time it ran
 *   range         positions sold in only one of the two periods (not because
 *                 they were out of stock), when their selling rate makes the
 *                 gap more than chance: at the rate of the period they sold
 *                 in, at least MIN_RANGE_UNITS expected in the other (so a
 *                 slow seller's quiet week is not taken for a delisting)
 *   unexplained   the rest — what is worth an investigation
 *
 *   store traffic  fewer or more shoppers (door counts when they cover the
 *                  scope, else receipts) — for one SKU (a finding), the
 *                  store's traffic applied to it, and the rest of the
 *                  department's trend beyond it
 *
 * Contributions: the change by store, department and SKU, each with its
 * largest driver. Every figure is rounded to cents and the rounding goes to
 * mix / unexplained, so the parts always add up to the total.
 */
class DriverAnalysis
{
    public const VERSION = 'drivers-1.1';

    public const MIN_RECEIPT_COVERAGE = 0.9;
    public const MIN_TRAFFIC_COVERAGE = 0.9;
    public const MIN_NORMAL_DAYS = 7;

    /**
     * A position counts as a range change when, at its selling rate, at least
     * this many units were to be expected in the period it did not sell in
     * (Poisson: a gap that long happens by chance under 5% of the time).
     */
    public const MIN_RANGE_UNITS = 3.0;
    public const LIFT_MIN = -0.8;
    public const LIFT_MAX = 2.0;
    public const EVENT_PRE_DAYS = 28;
    public const TOP = 5;

    public const LABELS = [
        'traffic' => 'Traffic (receipts)', 'visitors' => 'Visitors', 'conversion' => 'Conversion', 'penetration' => 'Category penetration',
        'basket' => 'Basket size', 'volume' => 'Volume', 'mix' => 'Mix', 'price' => 'Price',
        'availability' => 'Out of stock', 'promotions' => 'Promotions', 'events' => 'Calendar events', 'range' => 'Range changes',
        'store_traffic' => 'Store traffic', 'department' => 'Department trend', 'unexplained' => 'Unexplained',
    ];

    /**
     * @param  array{store_id?:?int, department?:?string, category?:?string, sku?:?string}  $scope
     * @return array<string,mixed>
     */
    public function analyze(int $tenantId, string $pFrom, string $pTo, string $cFrom, string $cTo, array $scope = [], string $comparisonKind = 'custom'): array
    {
        $scope = array_filter([
            'store_id'   => isset($scope['store_id']) ? (int) $scope['store_id'] : null,
            'department' => isset($scope['department']) ? (string) $scope['department'] : null,
            'category'   => isset($scope['category']) ? (string) $scope['category'] : null,
            'sku'        => isset($scope['sku']) ? (string) $scope['sku'] : null,
        ], fn ($v) => $v !== null);
        $dP = self::days($pFrom, $pTo);
        $dC = self::days($cFrom, $cTo);
        $ctx = compact('tenantId', 'pFrom', 'pTo', 'cFrom', 'cTo', 'dP', 'dC', 'scope');

        $promos = PromotionCalendar::load($tenantId, $cFrom < $pFrom ? $cFrom : $pFrom, $pTo > $cTo ? $pTo : $cTo);
        $rows = $this->positionEffects($ctx, $promos);
        $tot = $rows['total'];

        $RP = (float) $tot->r_p; $RC = (float) $tot->r_c;
        $UP = (float) $tot->u_p; $UC = (float) $tot->u_c;
        $change = $dP * ($RP / $dP - $RC / $dC);
        $price = (float) $tot->price;
        $notes = [];

        // ── WHAT ────────────────────────────────────────────────────────────
        $what = [];
        $traffic = isset($scope['sku']) ? null : $this->traffic($ctx);
        if ($RC > 0 && $UC > 0 && $UP > 0) {
            $aupC = $RC / $UC;
            $eU = $dP * ($UP / $dP - $UC / $dC) * $aupC;
            $L = self::logMean($UP / $dP, $UC / $dC);
            $factors = [];
            if ($traffic !== null) {
                if (isset($traffic['visitors_p'])) {
                    $factors['visitors'] = [$traffic['visitors_p'] / $dP, $traffic['visitors_c'] / $dC];
                    $factors['conversion'] = [$traffic['receipts_p'] / $traffic['visitors_p'], $traffic['receipts_c'] / $traffic['visitors_c']];
                } else {
                    $factors['traffic'] = [$traffic['receipts_p'] / $dP, $traffic['receipts_c'] / $dC];
                }
                if (isset($traffic['cat_receipts_p'])) {
                    $factors['penetration'] = [$traffic['cat_receipts_p'] / $traffic['receipts_p'], $traffic['cat_receipts_c'] / $traffic['receipts_c']];
                    $factors['basket'] = [$UP / $traffic['cat_receipts_p'], $UC / $traffic['cat_receipts_c']];
                } else {
                    $factors['basket'] = [$UP / $traffic['receipts_p'], $UC / $traffic['receipts_c']];
                }
            }
            $valid = $factors !== [] && array_reduce($factors, fn ($ok, $f) => $ok && $f[0] > 0 && $f[1] > 0, true);
            if ($valid) {
                foreach ($factors as $k => [$a, $b]) {
                    $what[$k] = $dP * $aupC * $L * log($a / $b);
                }
            } else {
                $what['volume'] = $eU;
                if (! isset($scope['sku'])) {
                    $notes[] = $traffic === null
                        ? 'The sales lines carry no receipt numbers, so traffic and basket size are shown together as volume.'
                        : 'Traffic could not be split for this scope.';
                }
            }
            $what['mix'] = $change - $price - $eU;
            $what['price'] = $price;
        } else {
            $what['volume'] = $change - $price;
            $what['price'] = $price;
            $notes[] = 'The comparison period has no sales in this scope, so the change cannot be split into traffic, basket and mix.';
        }

        // ── WHY ─────────────────────────────────────────────────────────────
        $why = [
            'availability' => (float) $tot->avail,
            'promotions'   => (float) $tot->promo,
            'range'        => (float) $tot->range_eff,
        ];
        $eventDetail = [];
        if (isset($scope['sku'])) {
            [$why['store_traffic'], $why['department'], $eventDetail] = $this->skuContext($ctx, $tot);
        } else {
            [$why['events'], $eventDetail] = $this->events($ctx, $rows['departments']);
            // Fewer (or more) shoppers came: the footfall when door counts cover
            // the scope (conversion then stays a question), else the receipts.
            $why['store_traffic'] = $what['visitors'] ?? $what['traffic'] ?? 0.0;
        }
        $why['unexplained'] = ($change - $price) - array_sum($why);
        $order = ['store_traffic', 'department', 'availability', 'promotions', 'events', 'range', 'unexplained'];
        $why = array_replace(array_intersect_key(array_flip($order), $why), $why);

        [$what, $why] = self::balance($what, $why, $change, $price);

        $result = [
            'version'    => self::VERSION,
            'scope'      => $scope,
            'period'     => ['from' => $pFrom, 'to' => $pTo, 'days' => $dP],
            'comparison' => ['from' => $cFrom, 'to' => $cTo, 'days' => $dC, 'kind' => $comparisonKind],
            'totals'     => [
                'revenue_p'  => round($RP, 2),
                'revenue_c'  => round($RC * $dP / $dC, 2),   // at the period's length
                'change'     => round($change, 2),
                'change_pct' => $RC > 0 ? round(100 * $change / ($RC * $dP / $dC), 1) : null,
                'units_p'    => round($UP, 2),
                'units_c'    => round($UC * $dP / $dC, 2),
                'receipts_p' => $traffic['receipts_p'] ?? null,
                'receipts_c' => isset($traffic['receipts_c']) ? round($traffic['receipts_c'] * $dP / $dC, 1) : null,
                'visitors_p' => $traffic['visitors_p'] ?? null,
                'visitors_c' => isset($traffic['visitors_c']) ? round($traffic['visitors_c'] * $dP / $dC, 1) : null,
                'traffic_basis' => isset($traffic['visitors_p']) ? 'visitors' : ($traffic !== null ? 'receipts' : null),
            ],
            'what'   => self::items($what),
            'why'    => self::items($why),
            'detail' => [
                'availability' => ['positions' => (int) $tot->avail_n],
                'promotions'   => ['positions' => (int) $tot->promo_n],
                'range'        => ['new' => (int) $tot->range_new, 'gone' => (int) $tot->range_gone],
                'events'       => $eventDetail,
            ],
            'contributors' => [
                'stores'      => isset($scope['store_id']) ? [] : $this->contributors($rows['stores'], 'store', $tenantId, $this->storeTraffic($ctx, $rows['stores'])),
                'departments' => isset($scope['sku']) ? [] : $this->contributors($rows['departments'], 'department', $tenantId),
                'skus'        => isset($scope['sku']) ? [] : $this->contributors($rows['skus'], 'sku', $tenantId),
            ],
            'notes'  => $notes,
            'headline' => '',
        ];
        $result['headline'] = self::headline($result);

        return $result;
    }

    /** One stored breakdown's items as {key: value}. @return array<string,float> */
    public static function map(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            $out[$i['key']] = (float) $i['value'];
        }

        return $out;
    }

    // ── position-level effects (one SQL pass, grouped four ways) ─────────────

    /** @return array{total:object, stores:array, skus:array, departments:array} */
    private function positionEffects(array $c, PromotionCalendar $promos): array
    {
        ['tenantId' => $t, 'pFrom' => $pf, 'pTo' => $pt, 'cFrom' => $cf, 'cTo' => $ct, 'dP' => $dP, 'dC' => $dC, 'scope' => $scope] = $c;

        $scopeSql = '';
        $bind = [];
        if (isset($scope['store_id'])) {
            $scopeSql .= ' AND sd.store_id = ?';
            $bind[] = $scope['store_id'];
        }
        if (isset($scope['department'])) {
            $scopeSql .= " AND COALESCE(TRIM(p.department), '') = ?";
            $bind[] = $scope['department'];
        }
        if (isset($scope['category'])) {
            $scopeSql .= " AND COALESCE(TRIM(p.category), '') = ?";
            $bind[] = $scope['category'];
        }
        if (isset($scope['sku'])) {
            $scopeSql .= ' AND sd.sku = ?';
            $bind[] = $scope['sku'];
        }
        $lo = min($pf, $cf);
        $hi = max($pt, $ct);
        $spans = $promos->json($promos->skus(), $lo, $hi);
        $inP = 'BETWEEN ? AND ?';
        // Units a position must have sold in one period for no sales in the other to be a range change.
        $newMin = sprintf('%.6F', self::MIN_RANGE_UNITS * $dP / max(1, $dC));
        $goneMin = sprintf('%.6F', self::MIN_RANGE_UNITS * $dC / max(1, $dP));
        $isNew = "u_c <= 0 AND u_p >= {$newMin} AND r_p > 0 AND NOT out_c";
        $isGone = "u_p <= 0 AND u_c >= {$goneMin} AND r_c > 0 AND NOT out_p";

        $sql = "WITH pos AS (
                SELECT sd.store_id, sd.sku, LEFT(COALESCE(TRIM(p.department), ''), 120) AS department,
                       SUM(sd.units_sold) FILTER (WHERE sd.date {$inP}) AS u_p, SUM(sd.revenue) FILTER (WHERE sd.date {$inP}) AS r_p,
                       SUM(sd.units_sold) FILTER (WHERE sd.date {$inP}) AS u_c, SUM(sd.revenue) FILTER (WHERE sd.date {$inP}) AS r_c
                  FROM sales_daily sd
                  LEFT JOIN products p ON p.tenant_id = sd.tenant_id AND p.sku = sd.sku
                 WHERE sd.tenant_id = ? AND sd.store_id IS NOT NULL AND (sd.date {$inP} OR sd.date {$inP}){$scopeSql}
                 GROUP BY 1, 2, 3
            ),
            inv AS (
                SELECT i.store_id, i.sku, i.as_of_date AS d, SUM(i.on_hand_qty) AS q
                  FROM inventory_levels i
                  JOIN pos ON pos.store_id = i.store_id AND pos.sku = i.sku
                 WHERE i.tenant_id = ? AND (i.as_of_date {$inP} OR i.as_of_date {$inP})
                 GROUP BY 1, 2, 3
            ),
            av AS (
                SELECT store_id, sku,
                       COUNT(*) FILTER (WHERE d {$inP}) AS obs_p, COUNT(*) FILTER (WHERE d {$inP} AND q <= 0) AS oos_p,
                       COUNT(*) FILTER (WHERE d {$inP}) AS obs_c, COUNT(*) FILTER (WHERE d {$inP} AND q <= 0) AS oos_c
                  FROM inv GROUP BY 1, 2
            ),
            cal AS (
                SELECT DISTINCT s.sku, s.st, gs::date AS d
                  FROM jsonb_to_recordset(?::jsonb) AS s(sku text, st bigint, f date, t date),
                       generate_series(s.f, s.t, interval '1 day') gs
            ),
            pday AS (
                SELECT DISTINCT pos.store_id, pos.sku, cal.d FROM pos JOIN cal ON cal.sku = pos.sku AND (cal.st IS NULL OR cal.st = pos.store_id)
            ),
            pr AS (
                SELECT pday.store_id, pday.sku,
                       COUNT(*) FILTER (WHERE pday.d {$inP}) AS pd_p, COUNT(*) FILTER (WHERE pday.d {$inP}) AS pd_c,
                       COALESCE(SUM(sd.units_sold) FILTER (WHERE pday.d {$inP}), 0) AS pu_p,
                       COALESCE(SUM(sd.units_sold) FILTER (WHERE pday.d {$inP}), 0) AS pu_c
                  FROM pday
                  LEFT JOIN sales_daily sd ON sd.tenant_id = ? AND sd.store_id = pday.store_id AND sd.sku = pday.sku AND sd.date = pday.d
                 GROUP BY 1, 2
            ),
            b AS (
                SELECT pos.store_id, pos.sku, pos.department,
                       COALESCE(pos.u_p, 0)::float AS u_p, COALESCE(pos.r_p, 0)::float AS r_p,
                       COALESCE(pos.u_c, 0)::float AS u_c, COALESCE(pos.r_c, 0)::float AS r_c,
                       COALESCE(av.obs_p, 0) AS obs_p, COALESCE(av.oos_p, 0) AS oos_p, COALESCE(av.obs_c, 0) AS obs_c, COALESCE(av.oos_c, 0) AS oos_c,
                       COALESCE(pr.pd_p, 0) AS pd_p, COALESCE(pr.pd_c, 0) AS pd_c, COALESCE(pr.pu_p, 0)::float AS pu_p, COALESCE(pr.pu_c, 0)::float AS pu_c
                  FROM pos LEFT JOIN av USING (store_id, sku) LEFT JOIN pr USING (store_id, sku)
            ),
            k AS (
                SELECT b.*,
                       CASE WHEN u_c > 0 AND r_c > 0 THEN r_c / u_c WHEN u_p > 0 AND r_p > 0 THEN r_p / u_p ELSE 0 END AS price_c,
                       (obs_p > 0 AND oos_p = obs_p) AS out_p, (obs_c > 0 AND oos_c = obs_c) AS out_c
                  FROM b
            ),
            e AS (
                SELECT k.*,
                       ?::float * (r_p / ?::float - r_c / ?::float) AS d_rev,
                       CASE WHEN u_p > 0 AND u_c > 0 AND r_p > 0 AND r_c > 0 THEN u_p * (r_p / u_p - r_c / u_c) ELSE 0 END AS price,
                       CASE WHEN {$isNew} THEN r_p
                            WHEN {$isGone} THEN -?::float * r_c / ?::float
                            ELSE 0 END AS range_eff,
                       CASE WHEN {$isNew} THEN 1 ELSE 0 END AS is_new,
                       CASE WHEN {$isGone} THEN 1 ELSE 0 END AS is_gone
                  FROM k
            ),
            f AS (
                SELECT e.*,
                       CASE WHEN range_eff = 0 AND obs_p > 0 AND obs_c > 0
                                 AND ((obs_p - oos_p)::float / obs_p * ?::float + (obs_c - oos_c)::float / obs_c * ?::float) > 0
                            THEN -?::float * ((oos_p::float / obs_p) - (oos_c::float / obs_c))
                                 * ((u_p + u_c) / ((obs_p - oos_p)::float / obs_p * ?::float + (obs_c - oos_c)::float / obs_c * ?::float))
                                 * price_c
                            ELSE 0 END AS avail,
                       CASE WHEN range_eff = 0 AND (pd_p + pd_c) > 0 AND (?::float + ?::float - pd_p - pd_c) >= ?
                            THEN ?::float * price_c * (
                                   (pu_p - (u_p + u_c - pu_p - pu_c) / (?::float + ?::float - pd_p - pd_c) * pd_p) / ?::float
                                 - (pu_c - (u_p + u_c - pu_p - pu_c) / (?::float + ?::float - pd_p - pd_c) * pd_c) / ?::float)
                            ELSE 0 END AS promo
                  FROM e
            )
            SELECT GROUPING(store_id) AS g_store, GROUPING(sku) AS g_sku, GROUPING(department) AS g_dept,
                   store_id, sku, department,
                   SUM(d_rev) AS d_rev, SUM(price) AS price, SUM(avail) AS avail, SUM(promo) AS promo, SUM(range_eff) AS range_eff,
                   SUM(u_p) AS u_p, SUM(r_p) AS r_p, SUM(u_c) AS u_c, SUM(r_c) AS r_c,
                   COUNT(*) FILTER (WHERE avail <> 0) AS avail_n, COUNT(*) FILTER (WHERE promo <> 0) AS promo_n,
                   SUM(is_new) AS range_new, SUM(is_gone) AS range_gone
              FROM f
             GROUP BY GROUPING SETS ((), (store_id), (sku), (department))";

        $params = array_merge(
            [$pf, $pt, $pf, $pt, $cf, $ct, $cf, $ct],          // pos filters
            [$t, $pf, $pt, $cf, $ct], $bind,                     // pos where
            [$t, $pf, $pt, $cf, $ct],                            // inv
            [$pf, $pt, $pf, $pt, $cf, $ct, $cf, $ct],            // av
            [$spans],                                            // cal
            [$pf, $pt, $cf, $ct, $pf, $pt, $cf, $ct],            // pr filters
            [$t],                                                // pr join
            [$dP, $dP, $dC],                                     // d_rev
            [$dP, $dC],                                          // range gone
            [$dP, $dC, $dP, $dP, $dC],                           // avail
            [$dP, $dC, self::MIN_NORMAL_DAYS, $dP, $dP, $dC, $dP, $dP, $dC, $dC],   // promo
        );

        $total = null; $stores = $skus = $depts = [];
        foreach (DB::select($sql, $params) as $r) {
            if ($r->g_store && $r->g_sku && $r->g_dept) {
                $total = $r;
            } elseif (! $r->g_store) {
                $stores[] = $r;
            } elseif (! $r->g_sku) {
                $skus[] = $r;
            } else {
                $depts[] = $r;
            }
        }
        $total ??= (object) ['d_rev' => 0, 'price' => 0, 'avail' => 0, 'promo' => 0, 'range_eff' => 0, 'u_p' => 0, 'r_p' => 0, 'u_c' => 0, 'r_c' => 0,
            'avail_n' => 0, 'promo_n' => 0, 'range_new' => 0, 'range_gone' => 0];

        return ['total' => $total, 'stores' => $stores, 'skus' => $skus, 'departments' => $depts];
    }

    // ── traffic ─────────────────────────────────────────────────────────────

    /** Receipts (and visitors, category receipts) for the scope, or null when receipts cannot be trusted. */
    private function traffic(array $c): ?array
    {
        ['tenantId' => $t, 'pFrom' => $pf, 'pTo' => $pt, 'cFrom' => $cf, 'cTo' => $ct, 'scope' => $scope] = $c;
        $q = DB::table('store_daily')->where('tenant_id', $t)
            ->when(isset($scope['store_id']), fn ($q) => $q->where('store_id', $scope['store_id']));
        $agg = fn (string $from, string $to) => (clone $q)->whereBetween('date', [$from, $to])
            ->selectRaw('SUM(receipts) AS receipts, SUM(lines) AS lines, SUM(lines_with_receipt) AS lwr, COUNT(*) AS days, COUNT(receipts) AS with_receipts')->first();
        $p = $agg($pf, $pt);
        $cc = $agg($cf, $ct);
        foreach ([$p, $cc] as $r) {
            if (! $r || (int) $r->lines === 0 || (int) $r->with_receipts < (int) $r->days
                || (int) $r->lwr / max(1, (int) $r->lines) < self::MIN_RECEIPT_COVERAGE || (float) $r->receipts <= 0) {
                return null;
            }
        }
        $out = ['receipts_p' => (float) $p->receipts, 'receipts_c' => (float) $cc->receipts];

        // Visitors when the door counts cover the scope's store-days.
        $v = fn (string $from, string $to) => DB::selectOne(
            'SELECT COUNT(*) AS days, COUNT(t.id) AS counted, COALESCE(SUM(t.visitors), 0) AS visitors
               FROM store_daily d LEFT JOIN store_traffic t ON t.tenant_id = d.tenant_id AND t.store_id = d.store_id AND t.date = d.date
              WHERE d.tenant_id = ? AND d.date BETWEEN ? AND ?' . (isset($scope['store_id']) ? ' AND d.store_id = ?' : ''),
            array_merge([$t, $from, $to], isset($scope['store_id']) ? [$scope['store_id']] : []));
        $vp = $v($pf, $pt);
        $vc = $v($cf, $ct);
        if ((int) $vp->days > 0 && (int) $vc->days > 0
            && (int) $vp->counted / (int) $vp->days >= self::MIN_TRAFFIC_COVERAGE && (int) $vc->counted / (int) $vc->days >= self::MIN_TRAFFIC_COVERAGE
            && (float) $vp->visitors > 0 && (float) $vc->visitors > 0) {
            $out += ['visitors_p' => (float) $vp->visitors, 'visitors_c' => (float) $vc->visitors];
        }

        // A category: the receipts that contain it (its penetration).
        if (isset($scope['category'])) {
            $cq = DB::table('category_daily')->where('tenant_id', $t)->where('category', $scope['category'])
                ->when(isset($scope['department']), fn ($q) => $q->where('department', $scope['department']))
                ->when(isset($scope['store_id']), fn ($q) => $q->where('store_id', $scope['store_id']));
            $rp = (float) (clone $cq)->whereBetween('date', [$pf, $pt])->sum('receipts');
            $rc = (float) (clone $cq)->whereBetween('date', [$cf, $ct])->sum('receipts');
            if ($rp > 0 && $rc > 0) {
                $out += ['cat_receipts_p' => $rp, 'cat_receipts_c' => $rc];
            }
        }

        return $out;
    }

    // ── events ──────────────────────────────────────────────────────────────

    /**
     * Calendar events, per department: (share of the period − share of the
     * comparison) × the department's lift the last time the event ran ×
     * its normal daily revenue.
     *
     * @return array{0:float, 1:array<int,array>}
     */
    private function events(array $c, array $departments): array
    {
        ['tenantId' => $t, 'pFrom' => $pf, 'pTo' => $pt, 'cFrom' => $cf, 'cTo' => $ct, 'dP' => $dP, 'dC' => $dC, 'scope' => $scope] = $c;
        $cal = RetailCalendar::load($t, min($pf, $cf), max($pt, $ct));
        if ($cal->isEmpty() || $departments === []) {
            return [0.0, []];
        }
        $store = $scope['store_id'] ?? null;
        $total = 0.0;
        $detail = [];
        foreach ($departments as $d) {
            $dept = (string) $d->department;
            $sp = $cal->groupShares([$dept], $store, $pf, $pt);
            $sc = $cal->groupShares([$dept], $store, $cf, $ct);
            foreach (array_unique(array_merge(array_keys($sp), array_keys($sc))) as $key) {
                $diff = ($sp[$key]['share'] ?? 0) - ($sc[$key]['share'] ?? 0);
                if (abs($diff) < 0.05) {
                    continue;
                }
                $e = $sp[$key] ?? $sc[$key];
                $lift = $this->eventLift($t, $key, $dept, min($pf, $cf));
                if ($lift === null) {
                    $detail[$key] ??= ['key' => $key, 'name' => $e['name'], 'value' => 0.0, 'lift' => null, 'measured' => false, 'departments' => []];
                    continue;
                }
                $baseDay = ((float) $d->r_c / $dC) / (1 + $lift * ($sc[$key]['share'] ?? 0));
                $v = $baseDay * $lift * $diff * $dP;
                $total += $v;
                $detail[$key] ??= ['key' => $key, 'name' => $e['name'], 'value' => 0.0, 'lift' => $lift, 'measured' => true, 'departments' => []];
                $detail[$key]['value'] += $v;
                $detail[$key]['measured'] = true;
                $detail[$key]['departments'][] = ['department' => $dept !== '' ? $dept : 'Unassigned', 'lift' => round($lift, 3), 'value' => round($v, 2)];
            }
        }
        foreach ($detail as &$x) {
            $x['value'] = round($x['value'], 2);
        }

        return [$total, array_values($detail)];
    }

    /** @var array<string,?float> */
    private array $liftCache = [];

    /**
     * A department's lift the last time an event ran (before $before): its
     * daily revenue over the event's span against the 28 days before it, all
     * stores. Null when that history is not there.
     */
    public function eventLift(int $tenantId, string $key, string $department, string $before): ?float
    {
        $ck = "{$tenantId}|{$key}|{$department}|{$before}";
        if (array_key_exists($ck, $this->liftCache)) {
            return $this->liftCache[$ck];
        }
        $prior = RetailEvent::where('tenant_id', $tenantId)->where('key', $key)
            ->whereRaw('ends_on + (tail_days * interval \'1 day\') < ?', [$before])
            ->orderByDesc('starts_on')->first();
        if (! $prior) {
            return $this->liftCache[$ck] = null;
        }
        $from = $prior->starts_on->copy()->subDays($prior->lead_days)->toDateString();
        $to = $prior->ends_on->copy()->addDays($prior->tail_days)->toDateString();
        $preTo = Carbon::parse($from)->subDay()->toDateString();
        $preFrom = Carbon::parse($from)->subDays(self::EVENT_PRE_DAYS)->toDateString();
        $rate = function (string $a, string $b) use ($tenantId, $department) {
            $r = DB::table('category_daily')->where('tenant_id', $tenantId)->where('department', $department)
                ->whereBetween('date', [$a, $b])->selectRaw('SUM(revenue) AS rev, COUNT(DISTINCT date) AS days')->first();

            return $r && (int) $r->days >= 0.8 * self::days($a, $b) ? (float) $r->rev / self::days($a, $b) : null;
        };
        $during = $rate($from, $to);
        $pre = $rate($preFrom, $preTo);
        if ($during === null || $pre === null || $pre <= 0) {
            return $this->liftCache[$ck] = null;
        }

        return $this->liftCache[$ck] = max(self::LIFT_MIN, min(self::LIFT_MAX, $during / $pre - 1));
    }

    // ── one SKU: the store's traffic and the department's trend ─────────────

    /** @return array{0:float, 1:float, 2:array} */
    private function skuContext(array $c, object $tot): array
    {
        ['tenantId' => $t, 'pFrom' => $pf, 'pTo' => $pt, 'cFrom' => $cf, 'cTo' => $ct, 'dP' => $dP, 'dC' => $dC, 'scope' => $scope] = $c;
        $UP = (float) $tot->u_p; $UC = (float) $tot->u_c; $RC = (float) $tot->r_c;
        if ($UC <= 0 || $RC <= 0) {
            return [0.0, 0.0, []];
        }
        $priceC = $RC / $UC;
        $ucDay = $UC / $dC;
        $store = $scope['store_id'] ?? null;

        // Shoppers: the store's receipts (all stores for a chain-level SKU).
        $rec = fn (string $a, string $b) => (float) DB::table('store_daily')->where('tenant_id', $t)
            ->when($store, fn ($q) => $q->where('store_id', $store))->whereBetween('date', [$a, $b])->sum('receipts');
        $tp = $rec($pf, $pt) / $dP;
        $tc = $rec($cf, $ct) / $dC;
        $trafficRatio = $tp > 0 && $tc > 0 ? $tp / $tc : 1.0;
        $storeTraffic = $dP * $ucDay * ($trafficRatio - 1) * $priceC;

        // The rest of the department (this SKU excluded), beyond the traffic move.
        $dept = (string) (DB::table('products')->where('tenant_id', $t)->where('sku', $scope['sku'])->value('department') ?? '');
        $deptEff = 0.0;
        $events = [];
        if ($dept !== '') {
            $du = fn (string $a, string $b) => (float) DB::table('sales_daily as sd')
                ->join('products as p', fn ($j) => $j->on('p.tenant_id', '=', 'sd.tenant_id')->on('p.sku', '=', 'sd.sku'))
                ->where('sd.tenant_id', $t)->where('p.department', $dept)->where('sd.sku', '!=', $scope['sku'])
                ->when($store, fn ($q) => $q->where('sd.store_id', $store))
                ->whereBetween('sd.date', [$a, $b])->sum('sd.units_sold');
            $dp = $du($pf, $pt) / $dP;
            $dc = $du($cf, $ct) / $dC;
            if ($dp > 0 && $dc > 0) {
                $deptEff = $dP * $ucDay * (($dp / $dc) - $trafficRatio) * $priceC;
            }
            $cal = RetailCalendar::load($t, min($pf, $cf), max($pt, $ct));
            if (! $cal->isEmpty()) {
                $sp = $cal->groupShares([$dept], $store, $pf, $pt);
                $sc = $cal->groupShares([$dept], $store, $cf, $ct);
                foreach (array_unique(array_merge(array_keys($sp), array_keys($sc))) as $key) {
                    $diff = ($sp[$key]['share'] ?? 0) - ($sc[$key]['share'] ?? 0);
                    if (abs($diff) >= 0.05) {
                        $events[] = ['key' => $key, 'name' => ($sp[$key] ?? $sc[$key])['name'], 'share_p' => round($sp[$key]['share'] ?? 0, 2),
                            'share_c' => round($sc[$key]['share'] ?? 0, 2), 'note' => 'part of the department trend'];
                    }
                }
            }
        }

        return [$storeTraffic, $deptEff, $events];
    }

    // ── contributors ────────────────────────────────────────────────────────

    /**
     * Each store's traffic effect (receipts, LMDI at its comparison average
     * price), for the stores whose receipts can be trusted.
     *
     * @return array<int,float> store id => effect
     */
    private function storeTraffic(array $c, array $stores): array
    {
        ['tenantId' => $t, 'pFrom' => $pf, 'pTo' => $pt, 'cFrom' => $cf, 'cTo' => $ct, 'dP' => $dP, 'dC' => $dC] = $c;
        $rec = DB::select(
            'SELECT store_id,
                    SUM(receipts) FILTER (WHERE date BETWEEN ? AND ?) AS rp, SUM(receipts) FILTER (WHERE date BETWEEN ? AND ?) AS rc,
                    COUNT(*) FILTER (WHERE receipts IS NULL) AS missing, SUM(lines) AS lines, SUM(lines_with_receipt) AS lwr
               FROM store_daily WHERE tenant_id = ? AND (date BETWEEN ? AND ? OR date BETWEEN ? AND ?)
              GROUP BY store_id',
            [$pf, $pt, $cf, $ct, $t, $pf, $pt, $cf, $ct]);
        $byStore = collect($rec)->keyBy('store_id');
        $out = [];
        foreach ($stores as $s) {
            $x = $byStore[$s->store_id] ?? null;
            $uP = (float) $s->u_p / $dP; $uC = (float) $s->u_c / $dC;
            if (! $x || (int) $x->missing > 0 || (float) $x->rp <= 0 || (float) $x->rc <= 0 || (float) $s->r_c <= 0 || $uP <= 0 || $uC <= 0
                || (int) $x->lwr / max(1, (int) $x->lines) < self::MIN_RECEIPT_COVERAGE) {
                continue;
            }
            $aup = (float) $s->r_c / (float) $s->u_c;
            $out[(int) $s->store_id] = $dP * $aup * self::logMean($uP, $uC) * log(((float) $x->rp / $dP) / ((float) $x->rc / $dC));
        }

        return $out;
    }

    /** The largest movers both ways, each with its largest driver. */
    private function contributors(array $rows, string $kind, int $tenantId, array $traffic = []): array
    {
        usort($rows, fn ($a, $b) => (float) $b->d_rev <=> (float) $a->d_rev);
        $up = array_values(array_filter($rows, fn ($r) => (float) $r->d_rev > 0));
        $down = array_values(array_filter($rows, fn ($r) => (float) $r->d_rev < 0));
        $pick = array_merge(array_slice($up, 0, self::TOP), array_reverse(array_slice(array_reverse($down), 0, self::TOP)));
        $seen = [];
        $out = [];
        $names = [];
        if ($kind === 'store') {
            $names = DB::table('stores')->where('tenant_id', $tenantId)->whereIn('id', array_map(fn ($r) => (int) $r->store_id, $pick) ?: [0])->pluck('name', 'id')->all();
        } elseif ($kind === 'sku') {
            $names = DB::table('products')->where('tenant_id', $tenantId)->whereIn('sku', array_map(fn ($r) => (string) $r->sku, $pick) ?: ['__'])->pluck('name', 'sku')->all();
        }
        foreach ($pick as $r) {
            $id = match ($kind) { 'store' => (int) $r->store_id, 'sku' => (string) $r->sku, default => (string) $r->department };
            if (isset($seen[$id]) || abs((float) $r->d_rev) < 0.005) {
                continue;
            }
            $seen[$id] = true;
            $parts = ['price' => (float) $r->price, 'availability' => (float) $r->avail, 'promotions' => (float) $r->promo, 'range' => (float) $r->range_eff];
            if ($kind === 'store' && isset($traffic[(int) $id])) {
                $parts['store_traffic'] = $traffic[(int) $id];
            }
            $parts['volume'] = (float) $r->d_rev - array_sum($parts);
            uasort($parts, fn ($a, $b) => abs($b) <=> abs($a));
            $main = array_key_first($parts);
            $out[] = [
                'id'      => $id,
                'name'    => match ($kind) { 'store' => $names[$id] ?? "Store #{$id}", 'sku' => $names[$id] ?? $id, default => $id !== '' ? $id : 'Unassigned' },
                'change'  => round((float) $r->d_rev, 2),
                'revenue_p' => round((float) $r->r_p, 2),
                'main'    => $main,
                'main_label' => self::LABELS[$main],
                'main_value' => round($parts[$main], 2),
            ];
        }

        return $out;
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    public static function days(string $from, string $to): int
    {
        return max(1, (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);
    }

    /** The logarithmic mean (a − b) / ln(a / b); a when a = b. */
    public static function logMean(float $a, float $b): float
    {
        if ($a <= 0 || $b <= 0) {
            return 0.0;
        }

        return abs($a - $b) < 1e-12 ? $a : ($a - $b) / log($a / $b);
    }

    /**
     * Round to cents and make the parts add up exactly: what = change,
     * why = change − price (rounding goes to mix / unexplained).
     */
    private static function balance(array $what, array $why, float $change, float $price): array
    {
        $what = array_map(fn ($v) => round((float) $v, 2), $what);
        $why = array_map(fn ($v) => round((float) $v, 2), $why);
        $change = round($change, 2);
        $sink = isset($what['mix']) ? 'mix' : 'volume';
        $what[$sink] = round($what[$sink] + ($change - array_sum($what)), 2);
        $target = round($change - $what['price'], 2);
        $why['unexplained'] = round($why['unexplained'] + ($target - array_sum($why)), 2);

        return [$what, $why];
    }

    /** @return array<int,array{key:string,label:string,value:float}> */
    private static function items(array $parts): array
    {
        $out = [];
        foreach ($parts as $k => $v) {
            $out[] = ['key' => $k, 'label' => self::LABELS[$k] ?? ucfirst($k), 'value' => (float) $v];
        }

        return $out;
    }

    /** One sentence: the change and its two largest drivers. */
    public static function headline(array $r): string
    {
        $chg = (float) $r['totals']['change'];
        if (abs($chg) < 0.005) {
            return 'Sales were level with the comparison period.';
        }
        $parts = array_filter(array_merge($r['what'], $r['why']), fn ($i) => ! in_array($i['key'], ['unexplained', 'mix', 'volume'], true)
            && abs($i['value']) >= 0.005 && ($i['value'] > 0) === ($chg > 0));
        usort($parts, fn ($a, $b) => abs($b['value']) <=> abs($a['value']));
        $top = array_slice($parts, 0, 2);
        $pct = $r['totals']['change_pct'];
        $lead = 'Sales ' . ($chg > 0 ? 'rose' : 'fell') . ($pct !== null ? ' ' . abs($pct) . '%' : '');
        if ($top === []) {
            return $lead . ', with no single measured driver behind it.';
        }

        return $lead . ', mostly ' . implode(' and ', array_map(fn ($i) => mb_strtolower($i['label']), $top)) . '.';
    }
}
