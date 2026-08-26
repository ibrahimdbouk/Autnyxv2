<?php

namespace App\Services\Anomaly;

use App\Models\AnomalySetting;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * B7 — the learning loop (recommend-only).
 *
 * Reads recorded investigation outcomes (real vs false-positive) per rule and,
 * where a rule has been noisy enough on a large enough sample, RECOMMENDS a
 * tighter materiality floor (or a wider tolerance band). It never applies the
 * change itself — the suggestion, with its evidence, is surfaced on the
 * Detection Rules screen for an admin to accept. Deterministic and auditable,
 * consistent with "Autnyx recommends, it never executes silently."
 *
 * For money-floor rules the recommendation is derived by IMPACT SEPARATION: if
 * most false alarms had a small estimated impact and the genuine ones didn't,
 * raise the floor to sit between them — cutting the noise while keeping the
 * real, valuable hits.
 */
class ThresholdTuningService
{
    /** Don't suggest anything below this many recorded outcomes for a rule. */
    private const MIN_SAMPLE = 8;

    /** Only act on rules whose false-positive rate is at least this. */
    private const FP_HIGH = 0.40;

    /** Need at least this many false-positive impacts to separate on value. */
    private const MIN_FP_IMPACTS = 3;

    /** Band-widening step / cap for pct-only rules. */
    private const PCT_STEP = 10.0;
    private const PCT_CAP  = 200.0;

    /**
     * @return array<string, array{
     *   key:string, current:float, suggested:float, fp_rate:float, sample:int,
     *   reason:string, basis:string
     * }>  keyed by rule_type
     */
    public function suggestionsForTenant(int $tenantId): array
    {
        $rows = DB::table('anomalies as a')
            ->join('investigation_outcomes as o', 'o.investigation_id', '=', 'a.investigation_id')
            ->where('a.tenant_id', $tenantId)
            ->whereNotNull('a.investigation_id')
            ->selectRaw("a.rule_type, o.was_false_positive, o.outcome_type, COALESCE((a.context->>'revenue_impact')::numeric, 0) AS impact")
            ->get();

        if ($rows->isEmpty()) return [];

        // Aggregate per rule: real/fp counts and the impact of each class.
        $stat = [];
        foreach ($rows as $r) {
            if ($r->outcome_type === 'duplicate') continue; // neither hit nor false alarm
            $rt = $r->rule_type;
            $stat[$rt] ??= ['real' => 0, 'fp' => 0, 'fpImpacts' => [], 'realImpacts' => []];
            if ($r->was_false_positive) {
                $stat[$rt]['fp']++;
                $stat[$rt]['fpImpacts'][] = (float) $r->impact;
            } else {
                $stat[$rt]['real']++;
                $stat[$rt]['realImpacts'][] = (float) $r->impact;
            }
        }

        $settings = AnomalySetting::where('tenant_id', $tenantId)->get()->keyBy('rule_type');
        $out = [];

        foreach ($stat as $rt => $s) {
            $sample = $s['real'] + $s['fp'];
            if ($sample < self::MIN_SAMPLE) continue;
            $fpRate = $s['fp'] / $sample;
            if ($fpRate < self::FP_HIGH) continue;

            $setting = $settings->get($rt);
            if ($setting === null) continue;
            $eff = $setting->getEffectiveThresholds();

            $key = isset($eff['min_revenue']) ? 'min_revenue'
                 : (isset($eff['min_value']) ? 'min_value'
                 : (isset($eff['pct']) ? 'pct' : null));
            if ($key === null) continue; // no lever to move

            $current = (float) ($eff[$key] ?? 0);
            $fpPct   = round($fpRate * 100);

            if ($key === 'pct') {
                $suggested = min(self::PCT_CAP, $current + self::PCT_STEP);
                $reason = "{$fpPct}% of {$sample} recorded outcomes were false positives — widen the tolerance band to cut the noise.";
            } else {
                if (count($s['fpImpacts']) < self::MIN_FP_IMPACTS) continue;
                $medFp   = $this->median($s['fpImpacts']);
                $p25Real = ! empty($s['realImpacts']) ? $this->percentile($s['realImpacts'], 25) : INF;

                // Raise to the median false-alarm impact, but never above the
                // cheapest genuine hits (keep p25 of real impacts surviving).
                $target = $medFp < $p25Real ? $medFp : max($current, $p25Real * 0.9);
                $suggested = $this->niceRound($target);

                $sep = $medFp < $p25Real ? '' : ' (weak value separation — gentle raise only)';
                $reason = "{$fpPct}% of {$sample} recorded outcomes were false positives; half the false alarms were under "
                    . Money::format($medFp, null, 0) . " of estimated impact{$sep}.";
            }

            if ($suggested <= $current) continue; // only ever tighten upward

            $out[$rt] = [
                'key'       => $key,
                'current'   => $current,
                'suggested' => $suggested,
                'fp_rate'   => round($fpRate, 3),
                'sample'    => $sample,
                'reason'    => $reason,
                'basis'     => 'outcomes',
            ];
        }

        return $out;
    }

    /** Merge a suggestion onto a setting's current thresholds (for applying). */
    public function applyTo(AnomalySetting $setting, array $suggestion): array
    {
        return array_merge($setting->thresholds ?? [], [$suggestion['key'] => $suggestion['suggested']]);
    }

    private function median(array $v): float
    {
        if (empty($v)) return 0.0;
        sort($v);
        $n = count($v);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float) $v[$mid] : ((float) $v[$mid - 1] + (float) $v[$mid]) / 2;
    }

    private function percentile(array $v, float $p): float
    {
        if (empty($v)) return 0.0;
        sort($v);
        $idx = (int) floor(($p / 100) * (count($v) - 1));
        return (float) $v[$idx];
    }

    /** Clean threshold numbers: 137 → 150, 2,340 → 2,500. */
    private function niceRound(float $v): float
    {
        if ($v <= 0) return 0;
        $mag  = 10 ** floor(log10($v));
        $step = $mag / 2;
        return (float) (ceil($v / $step) * $step);
    }
}
