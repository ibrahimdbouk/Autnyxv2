<?php

namespace App\Services\Recovery;

use App\Models\Anomaly;
use App\Models\AnomalySetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Recovery Measurement — R3: OBSERVED recovery, straight from the lifecycle.
 *
 * This reads what the R2 reconciler wrote. When an anomaly's condition stops
 * firing and stays clear across the confirmed number of *evaluated* runs, the
 * reconciler marks it `resolved` and dates `resolved_at` to the first clear.
 * The value that was surfaced as at-risk when it opened (`value_at_open`) is
 * therefore no longer at risk — that is OBSERVED recovery.
 *
 * Honesty rules baked in here (from product-principles.md):
 *   • OBSERVED ≠ ATTRIBUTED. This service never claims a cause. It reports that
 *     a problem the engine was watching cleared and stayed clear — nothing about
 *     *why*. Attributed recovery (an action drove a measured metric back up)
 *     lives in the investigation-scoped InvestigationOutcome layer and must not
 *     be summed with these figures.
 *   • Backfilled episodes are EXCLUDED from observed totals. Their `resolved`
 *     state was inferred from a historical `resolved_at` at import, not watched
 *     clearing across evaluated runs — so it is not something we observed. They
 *     are surfaced separately as a labelled count, never hidden.
 *   • A data gap is never recovery — the reconciler already guarantees this
 *     (dormant, not resolved), so a `resolved` row here is always confirmed.
 *
 * Every figure is a deterministic DB aggregate. AI is not involved.
 */
class AnomalyRecoveryService
{
    /**
     * OBSERVED recovery over a window (by `resolved_at`), excluding backfilled.
     *
     * @return array{amount: float, count: int}
     */
    public function observedInWindow(int $tenantId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $row = $this->resolvedBase($tenantId, $from, $to)
            ->selectRaw('COALESCE(SUM(value_at_open), 0) AS amount, COUNT(*) AS cnt')
            ->first();

        return [
            'amount' => (float) ($row->amount ?? 0),
            'count'  => (int) ($row->cnt ?? 0),
        ];
    }

    /** OBSERVED recovery recorded this calendar month. */
    public function mtd(int $tenantId): array
    {
        return $this->observedInWindow($tenantId, Carbon::now()->startOfMonth(), null);
    }

    /** OBSERVED recovery in the previous calendar month (for trend deltas). */
    public function prevMtd(int $tenantId): array
    {
        $start = Carbon::now()->subMonthNoOverflow()->startOfMonth();

        return $this->observedInWindow($tenantId, $start, Carbon::now()->startOfMonth());
    }

    /**
     * Value STILL at risk according to the lifecycle: Σ value_at_open over active
     * episodes (open / persisting / clearing — not dismissed, not resolved). The
     * honest denominator for "how much has cleared vs. how much is still live".
     */
    public function activeValueAtRisk(int $tenantId): float
    {
        return (float) Anomaly::query()
            ->where('tenant_id', $tenantId)
            ->active()
            ->sum('value_at_open');
    }

    /**
     * Daily OBSERVED recovery for the last $days days (for the sparkline / chart),
     * keyed 'Y-m-d' → summed value_at_open of episodes resolved that day.
     *
     * @return array<string,float>
     */
    public function dailySeries(int $tenantId, int $days = 30): array
    {
        $from = Carbon::now()->subDays($days - 1)->startOfDay();

        return $this->resolvedBase($tenantId, $from, null)
            ->selectRaw("TO_CHAR(resolved_at::date, 'YYYY-MM-DD') AS d, COALESCE(SUM(value_at_open), 0) AS total")
            ->groupByRaw("TO_CHAR(resolved_at::date, 'YYYY-MM-DD')")
            ->pluck('total', 'd')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * OBSERVED recovery grouped by rule family (rule_type), highest first.
     *
     * @return array<int,array{rule_type:string,label:string,recovered:float,count:int}>
     */
    public function byRuleFamily(int $tenantId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        return $this->resolvedBase($tenantId, $from, $to)
            ->groupBy('rule_type')
            ->selectRaw('rule_type, COALESCE(SUM(value_at_open), 0) AS recovered, COUNT(*) AS cnt')
            ->orderByDesc('recovered')
            ->get()
            ->map(fn ($r) => [
                'rule_type' => (string) $r->rule_type,
                'label'     => AnomalySetting::RULES[$r->rule_type]['label']
                    ?? ucwords(str_replace('_', ' ', (string) $r->rule_type)),
                'recovered' => (float) $r->recovered,
                'count'     => (int) $r->cnt,
            ])->all();
    }

    /**
     * Drill-down rows: the resolved episodes that make up observed recovery.
     *
     * @return \Illuminate\Support\Collection<int,Anomaly>
     */
    public function resolvedRows(int $tenantId, ?CarbonInterface $from = null, ?CarbonInterface $to = null, int $limit = 200)
    {
        return $this->resolvedBase($tenantId, $from, $to)
            ->orderByDesc('value_at_open')
            ->orderByDesc('resolved_at')
            ->limit($limit)
            ->get(['id', 'investigation_id', 'rule_type', 'sku', 'store_id', 'value_at_open', 'resolved_at', 'first_seen_at', 'episode_seq', 'occurrence_count']);
    }

    /**
     * Count of resolved episodes that are backfilled (historical, EXCLUDED from
     * observed totals) — surfaced so the exclusion is visible, never silent.
     */
    public function backfilledResolvedCount(int $tenantId): int
    {
        return (int) Anomaly::query()
            ->where('tenant_id', $tenantId)
            ->where('lifecycle_state', Anomaly::LIFECYCLE_RESOLVED)
            ->where('backfilled', true)
            ->count();
    }

    /**
     * One-shot summary for the funnel / headline surfaces.
     *
     * @return array{
     *   observed_total: float, observed_count: int,
     *   observed_mtd: float, observed_mtd_count: int,
     *   active_at_risk: float,
     *   clear_rate: float|null,
     *   backfilled_excluded: int
     * }
     */
    public function summary(int $tenantId): array
    {
        $all       = $this->observedInWindow($tenantId, null, null);
        $mtd       = $this->mtd($tenantId);
        $active    = $this->activeValueAtRisk($tenantId);
        $observed  = $all['amount'];

        // Clear rate: observed cleared ÷ (observed cleared + still-active value).
        $denom = $observed + $active;

        return [
            'observed_total'      => $observed,
            'observed_count'      => $all['count'],
            'observed_mtd'        => $mtd['amount'],
            'observed_mtd_count'  => $mtd['count'],
            'active_at_risk'      => $active,
            'clear_rate'          => $denom > 0 ? round($observed / $denom * 100, 1) : null,
            'backfilled_excluded' => $this->backfilledResolvedCount($tenantId),
        ];
    }

    // ── Internal ────────────────────────────────────────────────────────────

    /**
     * Base query for OBSERVED recovery: resolved, genuinely observed (not
     * backfilled), with a resolved_at timestamp, optionally windowed.
     */
    private function resolvedBase(int $tenantId, ?CarbonInterface $from, ?CarbonInterface $to)
    {
        $q = Anomaly::query()
            ->where('tenant_id', $tenantId)
            ->where('lifecycle_state', Anomaly::LIFECYCLE_RESOLVED)
            ->where(function ($w) {
                $w->where('backfilled', false)->orWhereNull('backfilled');
            })
            ->whereNotNull('resolved_at');

        if ($from !== null) {
            $q->where('resolved_at', '>=', Carbon::instance($from));
        }
        if ($to !== null) {
            $q->where('resolved_at', '<', Carbon::instance($to));
        }

        return $q;
    }
}
