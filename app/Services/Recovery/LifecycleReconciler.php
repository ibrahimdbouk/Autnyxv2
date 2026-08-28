<?php

namespace App\Services\Recovery;

use App\Models\Anomaly;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Recovery Measurement — R2: the single writer of anomaly lifecycle status.
 *
 * Runs immediately after a detection rule, in place of the old destructive
 * stale-sweep (which DELETED cleared anomalies — the reason recovery reporting
 * was empty). For a rule's active anomalies it advances the honest state
 * machine:
 *
 *   touched (still failing)        → persisting  (open on the very first sighting;
 *                                     a re-fire while clearing is a flap → persisting,
 *                                     clear-streak reset)
 *   not touched + evaluable        → clearing → resolved after N consecutive
 *                                     EVALUATED healthy runs, dated to the FIRST clear
 *   not touched + NOT evaluable    → dormant (no change) — a data gap is never
 *                                     read as recovery
 *
 * Resolution is confirmed, never assumed. Recovery is only ever advanced for a
 * subject the rule actually evaluated this run.
 */
class LifecycleReconciler
{
    /** Consecutive evaluated-healthy runs before an anomaly is confirmed resolved. */
    public const DEFAULT_CONFIRM_RUNS = 2;

    /**
     * How many consecutive evaluated-healthy runs each demand segment needs
     * before recovery is confirmed. Intermittent/lumpy SKUs are quiet by nature,
     * so a single "no failure" run can just be a demand gap — they require more
     * confirmation than smooth series before we call it recovery.
     */
    public const SEGMENT_CONFIRM_RUNS = [
        'smooth'       => 2,
        'erratic'      => 3,
        'intermittent' => 3,
        'lumpy'        => 4,
    ];

    public static function confirmRunsForSegment(?string $segment): int
    {
        return self::SEGMENT_CONFIRM_RUNS[$segment] ?? self::DEFAULT_CONFIRM_RUNS;
    }

    /**
     * @param  int[]  $touchedIds  anomaly ids flagged (still failing) this run
     * @param  \Closure(Anomaly): bool  $evaluable  was this subject actually evaluated this run?
     * @param  int|\Closure|null  $confirmRuns  fixed N, or a \Closure(Anomaly): int for
     *                                          per-subject (segment-aware) confirmation.
     */
    public function reconcileRule(
        int $tenantId,
        string $ruleType,
        array $touchedIds,
        \Closure $evaluable,
        int|\Closure|null $confirmRuns = null,
        ?CarbonInterface $now = null,
    ): void {
        $now = $now ? Carbon::instance($now) : Carbon::now();
        $touched = array_flip(array_map('intval', $touchedIds));

        // get() not cursor(): we write to the same table while iterating, and a
        // single rule's active set is bounded (indexed by tenant + rule_type).
        Anomaly::query()
            ->where('tenant_id', $tenantId)
            ->where('rule_type', $ruleType)
            ->whereNull('dismissed_at')
            ->where('lifecycle_state', '!=', Anomaly::LIFECYCLE_RESOLVED)
            ->get()
            ->each(function (Anomaly $anomaly) use ($touched, $evaluable, $confirmRuns, $now): void {
                if (isset($touched[$anomaly->id])) {
                    $this->markFailing($anomaly, $now);
                } elseif ($evaluable($anomaly)) {
                    $n = $confirmRuns instanceof \Closure
                        ? (int) $confirmRuns($anomaly)
                        : (int) ($confirmRuns ?? self::DEFAULT_CONFIRM_RUNS);
                    $this->markClearing($anomaly, max(1, $n), $now);
                }
                // else: dormant — not touched and not evaluable this run (data gap).
                //       Leave it exactly as it was; never resolve on absence of data.
            });
    }

    /** Still failing this run. */
    private function markFailing(Anomaly $anomaly, CarbonInterface $now): void
    {
        // Brand new = created this run (first_seen == last_seen, never advanced yet).
        $brandNew = $anomaly->first_seen_at !== null
            && $anomaly->last_seen_at !== null
            && $anomaly->first_seen_at->equalTo($anomaly->last_seen_at);

        $wasClearing = $anomaly->lifecycle_state === Anomaly::LIFECYCLE_CLEARING;

        $anomaly->last_seen_at = $now;
        $anomaly->cleared_at   = null;   // failing again — no active clear
        $anomaly->clear_streak = 0;

        if ($wasClearing) {
            // Flap: it was recovering, then failed again before confirmation.
            $anomaly->lifecycle_state = Anomaly::LIFECYCLE_PERSISTING;
        } elseif ($anomaly->lifecycle_state === Anomaly::LIFECYCLE_OPEN && ! $brandNew) {
            $anomaly->lifecycle_state = Anomaly::LIFECYCLE_PERSISTING;
        }

        if (! $brandNew) {
            $anomaly->occurrence_count = ($anomaly->occurrence_count ?? 1) + 1;
        }

        $anomaly->save();
    }

    /** Evaluated this run and no longer failing → clearing, confirmed to resolved. */
    private function markClearing(Anomaly $anomaly, int $confirmRuns, CarbonInterface $now): void
    {
        $anomaly->clear_streak = ($anomaly->clear_streak ?? 0) + 1;

        if ($anomaly->cleared_at === null) {
            $anomaly->cleared_at = $now; // dated to the FIRST clear, not to confirmation
        }

        if ($anomaly->clear_streak >= $confirmRuns) {
            $anomaly->lifecycle_state = Anomaly::LIFECYCLE_RESOLVED;
            $anomaly->resolved_at = $anomaly->cleared_at; // honest duration: first clear
        } else {
            $anomaly->lifecycle_state = Anomaly::LIFECYCLE_CLEARING;
        }

        $anomaly->save();
    }
}
