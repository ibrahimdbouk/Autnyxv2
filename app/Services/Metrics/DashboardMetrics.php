<?php

namespace App\Services\Metrics;

use App\Models\Action;
use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\Store;
use App\Support\Tenancy\TenantClock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * WP7.1 (audit H33, M27) — every figure on the tenant dashboard, computed in
 * one place (was ~20 queries inline in the Blade view) and cached per tenant
 * for a minute, so a room of users refreshing the dashboard costs one set of
 * queries.
 *
 * Definitions:
 *   • Stock figures (at risk, open, high priority) are what is open now.
 *     Their trend is a FLOW compared with a flow: new this week (last 7 days)
 *     against the 7 days before. (It compared everything open now with what
 *     was opened a week earlier.)
 *   • Recovery is RecoveryMetrics: tenant's month to date against the same
 *     stretch of last month.
 *   • Drivers and insights count ACTIVE anomalies (not dismissed, not
 *     resolved by the lifecycle).
 *   • Days are the tenant's days.
 *   • Transfer capacity: per SKU, units over order-up-to at some stores that
 *     other stores below their reorder point need — Σ min(surplus, deficit).
 *     Surplus nobody needs is not capacity.
 *
 * Per-user parts (links, permissions) and the short lists of records stay in
 * the page; only tenant-wide numbers are cached.
 */
class DashboardMetrics
{
    public const TTL = 60;

    public function __construct(private RecoveryMetrics $recovery) {}

    public function forTenant(int $tenantId): array
    {
        return Cache::remember(self::cacheKey($tenantId), self::TTL, fn () => $this->compute($tenantId));
    }

    public static function cacheKey(int $tenantId): string
    {
        return 'dash:' . $tenantId . ':metrics:v2';
    }

    public static function forget(int $tenantId): void
    {
        Cache::forget(self::cacheKey($tenantId));
    }

    public function compute(int $tenantId): array
    {
        $now      = now();
        $weekAgo  = $now->copy()->subDays(7);
        $twoWeeks = $now->copy()->subDays(14);
        $open     = fn () => Investigation::where('tenant_id', $tenantId)->whereIn('status', ['open', 'in_progress']);
        $opened   = fn ($from, $to) => Investigation::where('tenant_id', $tenantId)
            ->where('opened_at', '>=', $from)->where('opened_at', '<', $to);

        $openNow = $open()->toBase()->selectRaw(
            "COUNT(*) AS n, COALESCE(SUM(revenue_at_risk), 0) AS risk, COUNT(*) FILTER (WHERE priority IN ('high','critical')) AS high"
        )->first();
        $flow = fn ($from, $to) => $opened($from, $to)->toBase()->selectRaw(
            "COUNT(*) AS n, COALESCE(SUM(revenue_at_risk), 0) AS risk, COUNT(*) FILTER (WHERE priority IN ('high','critical')) AS high"
        )->first();
        $thisWeek = $flow($weekAgo, $now);
        $lastWeek = $flow($twoWeeks, $weekAgo);

        $overdue = Action::whereHas('investigation', fn ($q) => $q->where('tenant_id', $tenantId)->whereIn('status', ['open', 'in_progress']))
            ->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED])
            ->where('due_at', '<', $now)
            ->count();

        $rec = $this->recovery->summary($tenantId);

        $statusBreakdown = Investigation::where('tenant_id', $tenantId)
            ->selectRaw('status, count(*) as cnt')->groupBy('status')
            ->pluck('cnt', 'status')->map(fn ($v) => (int) $v)->toArray();

        // ── 30 tenant-local days ────────────────────────────────────────
        $localToday = TenantClock::now($tenantId)->startOfDay();
        $openedDay  = TenantClock::localDateSql('opened_at', $tenantId);
        $atRiskDaily = Investigation::where('tenant_id', $tenantId)
            ->where('opened_at', '>=', TenantClock::today($tenantId)->subDays(29))
            ->whereNotNull('revenue_at_risk')
            ->toBase()
            ->selectRaw("TO_CHAR({$openedDay}, 'YYYY-MM-DD') AS d, SUM(revenue_at_risk) AS total")
            ->groupByRaw("TO_CHAR({$openedDay}, 'YYYY-MM-DD')")
            ->pluck('total', 'd')->all();
        $recoveredDaily = $this->recovery->attributedDaily($tenantId, 30);
        $clearedDaily   = $this->recovery->observedDaily($tenantId, 30);

        $chart = ['labels' => [], 'atRisk' => [], 'recovered' => [], 'cleared' => []];
        for ($i = 29; $i >= 0; $i--) {
            $d = $localToday->copy()->subDays($i);
            $k = $d->format('Y-m-d');
            $chart['labels'][]    = $d->format('M j');
            $chart['atRisk'][]    = round((float) ($atRiskDaily[$k] ?? 0), 2);
            $chart['recovered'][] = round((float) ($recoveredDaily[$k] ?? 0), 2);
            $chart['cleared'][]   = round((float) ($clearedDaily[$k] ?? 0), 2);
        }

        // ── Drivers and insights: active anomalies ──────────────────────
        $active = fn () => Anomaly::where('tenant_id', $tenantId)->active();
        $byRule = fn ($q) => $q->selectRaw('rule_type, count(*) as cnt')->groupBy('rule_type')->orderByDesc('cnt')->orderBy('rule_type');

        $topDrivers = $byRule($active())->limit(5)->toBase()->get()
            ->map(fn ($r) => ['rule_type' => $r->rule_type, 'cnt' => (int) $r->cnt])->all();
        $recurring = $byRule($active()->where('ai_is_recurring', true))->toBase()->first();
        $monthTop  = $byRule($active()->where('detected_at', '>=', TenantClock::startOfMonth($tenantId)))->toBase()->first();
        $storeTop  = $active()->whereNotNull('store_id')->where('detected_at', '>=', $twoWeeks)
            ->selectRaw('store_id, count(*) as cnt')->groupBy('store_id')->orderByDesc('cnt')->orderBy('store_id')
            ->toBase()->first();
        $storeName = $storeTop ? Store::where('tenant_id', $tenantId)->whereKey($storeTop->store_id)->value('name') : null;

        $narrated = $open()->toBase()->selectRaw('COUNT(*) AS n, COUNT(ai_generated_at) AS narrated')->first();

        return [
            'computed_at' => $now->toIso8601String(),
            'kpi' => [
                'revenue_at_risk' => (float) $openNow->risk,
                'open'            => (int) $openNow->n,
                'high'            => (int) $openNow->high,
                'overdue'         => $overdue,
                'recovered_mtd'   => $rec['attributed'],
                'cleared_mtd'     => $rec['observed'],
            ],
            'flow' => [
                'risk' => [(float) $thisWeek->risk, (float) $lastWeek->risk],
                'open' => [(int) $thisWeek->n, (int) $lastWeek->n],
                'high' => [(int) $thisWeek->high, (int) $lastWeek->high],
            ],
            'prev' => [
                'recovered_mtd' => $rec['attributed_prev'],
                'cleared_mtd'   => $rec['observed_prev'],
            ],
            'status'   => $statusBreakdown,
            'chart'    => $chart,
            'drivers'  => $topDrivers,
            'insights' => [
                'recurring' => $recurring ? ['rule_type' => $recurring->rule_type, 'cnt' => (int) $recurring->cnt] : null,
                'month_top' => $monthTop ? ['rule_type' => $monthTop->rule_type, 'cnt' => (int) $monthTop->cnt] : null,
                'store'     => $storeTop && $storeName !== null
                    ? ['id' => (int) $storeTop->store_id, 'name' => $storeName, 'cnt' => (int) $storeTop->cnt] : null,
            ],
            'pulse' => [
                'transfer'     => $this->transferCapacity($tenantId),
                'narrated_pct' => (int) $narrated->n > 0 ? (int) round($narrated->narrated / $narrated->n * 100) : null,
            ],
        ];
    }

    /**
     * Units that could move between stores today: per SKU, the surplus above
     * order-up-to at some stores matched against the need of stores below
     * their reorder point, min(Σ surplus, Σ need). A store cannot supply
     * itself, and a SKU with surplus but no store short of it adds nothing.
     *
     * @return array{units: int, skus: int, donors: int, receivers: int}
     */
    public function transferCapacity(int $tenantId): array
    {
        $row = DB::selectOne(<<<'SQL'
            WITH pos AS (
                SELECT sku, store_id,
                       GREATEST(on_hand - order_up_to, 0) AS surplus,
                       CASE WHEN on_hand < reorder_point THEN GREATEST(order_up_to - on_hand, 0) ELSE 0 END AS need
                FROM sku_replenishment
                WHERE tenant_id = ? AND store_id IS NOT NULL AND order_up_to > 0
            ),
            matched AS (
                SELECT sku, LEAST(SUM(surplus), SUM(need)) AS units
                FROM pos GROUP BY sku
                HAVING SUM(surplus) > 0 AND SUM(need) > 0
            )
            SELECT
                (SELECT COALESCE(SUM(FLOOR(units)), 0) FROM matched) AS units,
                (SELECT COUNT(*) FROM matched) AS skus,
                (SELECT COUNT(DISTINCT p.store_id) FROM pos p JOIN matched m USING (sku) WHERE p.surplus > 0) AS donors,
                (SELECT COUNT(DISTINCT p.store_id) FROM pos p JOIN matched m USING (sku) WHERE p.need > 0) AS receivers
            SQL, [$tenantId]);

        return [
            'units'     => (int) ($row->units ?? 0),
            'skus'      => (int) ($row->skus ?? 0),
            'donors'    => (int) ($row->donors ?? 0),
            'receivers' => (int) ($row->receivers ?? 0),
        ];
    }

    /**
     * A trend between two like-for-like figures. `good` says whether the
     * movement is good news (more recovery is; more risk is not), so the
     * colour follows meaning, not direction.
     *
     * @return array{pct: ?float, dir: string, good: ?bool}
     */
    public static function trend(float $current, float $prev, bool $upIsGood): array
    {
        if ($prev == 0.0) {
            return ['pct' => null, 'dir' => $current > 0 ? 'up' : 'flat', 'good' => null];
        }
        $pct = round((($current - $prev) / abs($prev)) * 100, 1);
        $dir = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat');

        return ['pct' => abs($pct), 'dir' => $dir, 'good' => $dir === 'flat' ? null : (($dir === 'up') === $upIsGood)];
    }
}
