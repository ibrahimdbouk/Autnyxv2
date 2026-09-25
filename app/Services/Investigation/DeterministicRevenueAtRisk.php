<?php

namespace App\Services\Investigation;

use App\Models\Anomaly;
use App\Models\Investigation;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Support\Detection\ValueModel;

/**
 * The single, deterministic definition of an investigation's revenue at risk.
 *
 * WP1.1 (audit C1): this is the ONLY writer of investigations.revenue_at_risk.
 * AI output never feeds it (see InvestigationNarratorService → ai_revenue_estimate).
 *
 * v1: Σ revenue_impact on every member anomaly.
 *
 * v2 (WP4.4 / audit H21), for tenants on the corrected rules:
 *   • only LIVE members count (not dismissed, not resolved);
 *   • only lost revenue counts — stock and goods value at cost goes to
 *     capital_at_risk, beside it, never into it; upside and data-quality
 *     findings count toward neither;
 *   • one loss is counted once: overlapping rules on the same subject (a
 *     stockout and a sales drop on the same store + SKU) take the largest,
 *     and a SKU's chain-level figure and its per-store figures are not added
 *     together — the larger of the two stands.
 */
class DeterministicRevenueAtRisk
{
    public function compute(Investigation $investigation): float
    {
        if (AnomalyDetectionService::rulesV2For((int) $investigation->tenant_id)) {
            return $this->computeV2($investigation)['revenue'];
        }

        $total = 0.0;
        foreach ($investigation->anomalies()->get(['context']) as $a) {
            $total += (float) ($a->context['revenue_impact'] ?? 0);
        }

        return round($total, 2);
    }

    public function sync(Investigation $investigation): float
    {
        if (AnomalyDetectionService::rulesV2For((int) $investigation->tenant_id)) {
            $v = $this->computeV2($investigation);
            $investigation->update(['revenue_at_risk' => $v['revenue'], 'capital_at_risk' => $v['capital']]);

            return $v['revenue'];
        }

        $total = $this->compute($investigation);
        $investigation->update(['revenue_at_risk' => $total]);

        return $total;
    }

    /** @return array{revenue: float, capital: float} */
    public function computeV2(Investigation $investigation): array
    {
        $members = $investigation->anomalies()->active()
            ->get(['id', 'rule_type', 'sku', 'store_id', 'context', 'value_type']);

        return [
            'revenue' => self::deduplicated($members, ValueModel::LOST_REVENUE),
            'capital' => self::deduplicated($members, ValueModel::CAPITAL),
        ];
    }

    /**
     * @param  iterable<Anomaly>  $anomalies
     */
    public static function deduplicated(iterable $anomalies, string $type): float
    {
        // sku => ['chain' => max, 'stores' => [store => max]]; SKU-less → by store/subject.
        $bySku = [];
        $other = [];
        foreach ($anomalies as $a) {
            $context = is_array($a->context) ? $a->context : [];
            if (($a->value_type ?: ValueModel::type((string) $a->rule_type, $context)) !== $type) {
                continue;
            }
            $v = ValueModel::amount($context);
            if ($v <= 0) {
                continue;
            }
            if ($a->sku === null || $a->sku === '') {
                $k = ($a->store_id ?? '-') . '|' . ($context['subject'] ?? $a->rule_type);
                $other[$k] = max($other[$k] ?? 0.0, $v);
                continue;
            }
            if ($a->store_id === null) {
                $bySku[$a->sku]['chain'] = max($bySku[$a->sku]['chain'] ?? 0.0, $v);
            } else {
                $bySku[$a->sku]['stores'][$a->store_id] = max($bySku[$a->sku]['stores'][$a->store_id] ?? 0.0, $v);
            }
        }

        $total = array_sum($other);
        foreach ($bySku as $s) {
            $total += max($s['chain'] ?? 0.0, array_sum($s['stores'] ?? []));
        }

        return round($total, 2);
    }
}
