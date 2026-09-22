<?php

namespace App\Services\Investigation;

use App\Models\Investigation;
use App\Models\InvestigationEvidence;

/**
 * DEEP INVESTIGATION — deterministic What-If / Action Simulator.
 *
 * The ONLY net-new computation in Deep Investigation. It PROJECTS (never claims)
 * what a stockout-family signal costs if left alone versus if the recommended
 * replenishment is actioned now — using governed inputs already on the
 * investigation (on-hand, days of cover, lead time, unit price) and transparent
 * arithmetic. It measures nothing.
 *
 * PRINCIPLES:
 *   - Every output is a PROJECTION and must be rendered under a clear SIMULATED
 *     label. It is not a measured outcome and never becomes one.
 *   - Gated on inputs: with no on-hand + sales rate there is nothing to project,
 *     and it says so rather than inventing a baseline.
 *   - Deterministic and explainable: the assumptions are returned alongside the
 *     numbers, so the projection is reproducible by hand.
 *
 * See claude/deep-investigation.md.
 */
class ProjectionService
{
    private const STOCKOUT_RULES = ['stockout_risk', 'safety_stock_breach'];
    private const HORIZONS = [7, 14, 30];
    private const DEFAULT_HORIZON = 14;

    /** @return array<string,mixed> */
    public function forInvestigation(Investigation $investigation): array
    {
        // The signal worth simulating: a stockout-family anomaly with a SKU.
        $anomaly = $investigation->anomalies
            ->first(fn ($a) => in_array($a->rule_type, self::STOCKOUT_RULES, true) && $a->sku !== null);

        if ($anomaly === null) {
            return $this->gate('The What-If simulator applies to stockout / safety-stock signals. None is present on this investigation.');
        }

        $evidence = $investigation->evidence->where('anomaly_id', $anomaly->id);

        $onHand      = $this->numByLabel($evidence, 'Current on-hand quantity');
        $daysOfCover = $this->numByLabel($evidence, 'Days of cover');
        $avgBaseline = $this->numByLabel($evidence, 'Baseline mean daily sales');
        $leadTime    = $this->leadTime($investigation, $anomaly, $evidence);
        $unitPrice   = $this->unitPrice($evidence);

        // Average daily sales: prefer on-hand ÷ days-of-cover (they are produced
        // together), else the 90-day baseline mean.
        $avgDaily = null;
        if ($onHand !== null && $daysOfCover !== null && $daysOfCover > 0) {
            $avgDaily = round($onHand / $daysOfCover, 2);
        } elseif ($avgBaseline !== null && $avgBaseline > 0) {
            $avgDaily = $avgBaseline;
            if ($onHand !== null) {
                $daysOfCover = round($onHand / $avgBaseline, 1);
            }
        }

        if ($onHand === null || $avgDaily === null || $avgDaily <= 0 || $daysOfCover === null) {
            return $this->gate('Not enough governed inputs to simulate yet — a current on-hand level and a sales rate are required. They are collected when the investigation is opened or narrated.');
        }

        $scenarios = [];
        foreach (self::HORIZONS as $h) {
            $scenarios[$h] = $this->project($daysOfCover, $leadTime, $avgDaily, $unitPrice, $h);
        }

        return [
            'available'       => true,
            'empty_reason'    => null,
            'simulated'       => true,
            'sku'             => $anomaly->sku,
            'store_id'        => $anomaly->store_id,
            'inputs'          => [
                'on_hand'       => $onHand,
                'avg_daily'     => $avgDaily,
                'days_of_cover' => $daysOfCover,
                'lead_time'     => $leadTime,
                'unit_price'    => $unitPrice,
            ],
            'has_revenue'     => $unitPrice !== null,
            'horizons'        => self::HORIZONS,
            'default_horizon' => self::DEFAULT_HORIZON,
            'scenarios'       => $scenarios,
            'assumptions'     => $this->assumptions($onHand, $avgDaily, $daysOfCover, $leadTime, $unitPrice),
        ];
    }

    /**
     * The deterministic projection for one horizon.
     * "Do nothing": stock runs out at `days_of_cover`; every day after that in the
     *   horizon is a lost-sales day.
     * "Act now": replenishment arrives after `lead_time` days; lost-sales days are
     *   only the gap between running out and the delivery arriving.
     */
    private function project(float $daysOfCover, ?int $leadTime, float $avgDaily, ?float $unitPrice, int $horizon): array
    {
        $daysOutNoAction = max(0.0, $horizon - $daysOfCover);

        $daysOutWithAction = $leadTime === null
            ? $daysOutNoAction // no known lead time → cannot claim the gap closes
            : max(0.0, min((float) $leadTime, (float) $horizon) - $daysOfCover);

        $lostUnitsNo   = round($daysOutNoAction * $avgDaily);
        $lostUnitsWith = round($daysOutWithAction * $avgDaily);
        $protectedUnits = max(0, $lostUnitsNo - $lostUnitsWith);

        $row = [
            'horizon'             => $horizon,
            'stockout_in_days'    => round($daysOfCover, 1),
            'no_action'           => ['days_out' => round($daysOutNoAction, 1), 'lost_units' => $lostUnitsNo],
            'with_action'         => ['days_out' => round($daysOutWithAction, 1), 'lost_units' => $lostUnitsWith, 'lead_known' => $leadTime !== null],
            'protected_units'     => $protectedUnits,
            'lost_revenue_no'     => null,
            'lost_revenue_with'   => null,
            'protected_revenue'   => null,
        ];

        if ($unitPrice !== null) {
            $row['lost_revenue_no']   = round($lostUnitsNo * $unitPrice, 2);
            $row['lost_revenue_with'] = round($lostUnitsWith * $unitPrice, 2);
            $row['protected_revenue'] = round($protectedUnits * $unitPrice, 2);
        }

        return $row;
    }

    /** @return list<string> */
    private function assumptions(float $onHand, float $avgDaily, float $daysOfCover, ?int $leadTime, ?float $unitPrice): array
    {
        $a = [
            'On-hand ' . $this->n($onHand) . ' units at ' . $this->n($avgDaily) . ' units/day ⇒ ~' . $this->n($daysOfCover) . ' days of cover.',
            'Sales continue at the recent daily rate; no substitution or backorder capture.',
        ];
        $a[] = $leadTime !== null
            ? 'Acting now places the order today; stock arrives in ~' . $leadTime . ' days (the derived lead time).'
            : 'No lead time is on file, so the simulator does not assume the gap closes when acting.';
        $a[] = $unitPrice !== null
            ? 'Lost revenue = lost units × ' . $this->n($unitPrice) . ' per unit (recent average).'
            : 'No unit price on file — the projection is shown in units only.';

        return $a;
    }

    // ── input extraction (governed evidence + replenishment target) ──────────

    private function numByLabel($evidence, string $needle): ?float
    {
        foreach ($evidence as $e) {
            if ($e->value_numeric !== null && stripos((string) $e->label, $needle) !== false) {
                return (float) $e->value_numeric;
            }
        }

        return null;
    }

    private function leadTime(Investigation $investigation, $anomaly, $evidence): ?int
    {
        try {
            $rep = \App\Models\SkuReplenishment::where('tenant_id', $investigation->tenant_id)
                ->where('sku', $anomaly->sku)
                ->when($anomaly->store_id, fn ($q) => $q->where('store_id', $anomaly->store_id))
                ->first();
            if ($rep && $rep->lead_time_days !== null) {
                return (int) round((float) $rep->lead_time_days);
            }
        } catch (\Throwable) {
            // fall through to evidence
        }

        $fromEvidence = $this->numByLabel($evidence, 'Average supplier lead time');

        return $fromEvidence !== null ? (int) round($fromEvidence) : null;
    }

    private function unitPrice($evidence): ?float
    {
        foreach ($evidence as $e) {
            if (is_array($e->value_json) && isset($e->value_json['avg']) && is_numeric($e->value_json['avg']) && (float) $e->value_json['avg'] > 0) {
                return round((float) $e->value_json['avg'], 4);
            }
        }
        $cost = $this->numByLabel($evidence, 'Unit cost');

        return $cost !== null && $cost > 0 ? $cost : null;
    }

    private function n(float $v): string
    {
        return (floor($v) === $v) ? number_format($v, 0) : rtrim(rtrim(number_format($v, 2), '0'), '.');
    }

    /** @return array{available:false, empty_reason:string, simulated:true} */
    private function gate(string $reason): array
    {
        return ['available' => false, 'empty_reason' => $reason, 'simulated' => true];
    }
}
