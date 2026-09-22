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

    /**
     * Assumed days for an intra-network store transfer to arrive. A transfer only
     * earns a place in the simulation when it beats the supplier PO's derived lead
     * time; this constant is stated plainly as an assumption in the UI so the team
     * can weigh it against their own network reality.
     */
    private const TRANSFER_LEAD_DAYS = 2;

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

        // Governed replenishment parameters for this (store, SKU) — the same
        // nightly-derived row the reorder logic uses (on-hand, daily rate, lead
        // time, unit cost). Investigation evidence is the PRIMARY source; where it
        // is silent, these fill the gap so the simulator works on a real signal
        // instead of sitting empty. Nothing here is invented — every value is a
        // stored governed figure.
        $rep = $this->repFor($investigation, $anomaly);

        $onHand      = $this->numByLabel($evidence, 'Current on-hand quantity')
            ?? (($rep && $rep->on_hand !== null) ? (float) $rep->on_hand : null);
        $daysOfCover = $this->numByLabel($evidence, 'Days of cover');
        $avgBaseline = $this->numByLabel($evidence, 'Baseline mean daily sales');
        $leadTime    = $this->leadTime($investigation, $anomaly, $evidence, $rep);
        $unitPrice   = $this->unitPrice($evidence)
            ?? (($rep && (float) $rep->unit_cost > 0) ? round((float) $rep->unit_cost, 4) : null);

        // Average daily sales: prefer on-hand ÷ days-of-cover (produced together),
        // then the 90-day baseline mean, then the replenishment model's derived
        // daily rate (the same rate that drives this store-SKU's reorder point).
        $avgDaily = null;
        $rateFromModel = false;
        if ($onHand !== null && $daysOfCover !== null && $daysOfCover > 0) {
            $avgDaily = round($onHand / $daysOfCover, 2);
        } elseif ($avgBaseline !== null && $avgBaseline > 0) {
            $avgDaily = $avgBaseline;
            if ($onHand !== null) {
                $daysOfCover = round($onHand / $avgBaseline, 1);
            }
        } elseif ($rep && (float) $rep->daily_rate > 0) {
            $avgDaily = round((float) $rep->daily_rate, 2);
            $rateFromModel = true;
            if ($onHand !== null && $daysOfCover === null) {
                $daysOfCover = round($onHand / $avgDaily, 1);
            }
        }

        if ($onHand === null || $avgDaily === null || $avgDaily <= 0 || $daysOfCover === null) {
            return $this->gate('Not enough governed inputs to simulate yet — a current on-hand level and a sales rate are required. They come from the investigation evidence or the replenishment model for this store-SKU.');
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
            'assumptions'     => $this->assumptions($onHand, $avgDaily, $daysOfCover, $leadTime, $unitPrice, $rateFromModel),
            // Optional intra-network transfer alternative — present only when a
            // sibling store genuinely holds releasable surplus of this SKU and a
            // transfer would beat the supplier PO. Null otherwise (nothing shown).
            'transfer'        => $this->transferAlternative($investigation, $anomaly, $avgDaily, $daysOfCover, $leadTime, $unitPrice),
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

        $lostUnitsNo    = (int) round($daysOutNoAction * $avgDaily);
        $lostUnitsWith  = (int) round($daysOutWithAction * $avgDaily);
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

    /**
     * The intra-network transfer alternative (SIMULATED, optional).
     *
     * A stockout at one store is often coverable from a sibling store that is
     * holding this SKU ABOVE its own replenishment target — releasable surplus.
     * A transfer typically arrives faster than a fresh supplier PO, so it can
     * protect units the PO's lead-time gap would otherwise lose. This projects
     * that, using only governed per-store figures (SkuReplenishment.on_hand vs
     * its order_up_to / reorder_point), and returns null — showing nothing — when
     * there is no faster-than-PO transfer or no genuine surplus to move. Invents
     * no route and moves nothing; Autnyx recommends only.
     *
     * @return array<string,mixed>|null
     */
    private function transferAlternative(Investigation $investigation, $anomaly, float $avgDaily, float $daysOfCover, ?int $leadTime, ?float $unitPrice): ?array
    {
        // A transfer only earns its place when it beats the supplier PO. With no
        // known PO lead time, or a lead time no slower than a transfer, there is
        // no speed advantage to project.
        if ($leadTime === null || $leadTime <= self::TRANSFER_LEAD_DAYS) {
            return null;
        }

        try {
            $siblings = \App\Models\SkuReplenishment::where('tenant_id', $investigation->tenant_id)
                ->where('sku', $anomaly->sku)
                ->when($anomaly->store_id !== null, fn ($q) => $q->where('store_id', '!=', $anomaly->store_id))
                ->whereNotNull('on_hand')
                ->get();
        } catch (\Throwable) {
            return null;
        }

        // Releasable surplus = on-hand a donor store holds above its OWN target,
        // so releasing it keeps that store at target (it is not robbed to cover us).
        // The target must be a real, computed value (> 0): order_up_to / reorder_point
        // both DEFAULT to 0 in the schema, and a 0 target would falsely count the
        // whole on-hand as surplus.
        $donors = [];
        foreach ($siblings as $s) {
            $target = ((float) $s->order_up_to > 0)
                ? (float) $s->order_up_to
                : (((float) $s->reorder_point > 0) ? (float) $s->reorder_point : null);
            if ($target === null || $s->store_id === null) {
                continue;
            }
            $surplus = (float) $s->on_hand - $target;
            if ($surplus >= 1.0) {
                $donors[] = ['store_id' => $s->store_id, 'surplus' => (int) floor($surplus)];
            }
        }
        if (empty($donors)) {
            return null;
        }
        usort($donors, fn ($a, $b) => $b['surplus'] <=> $a['surplus']);
        $best = $donors[0];
        $totalSurplus = (int) array_sum(array_column($donors, 'surplus'));

        // Units at risk in the window between running out and the PO landing.
        $gapUnitsUntilPo = (int) round(max(0.0, $leadTime - $daysOfCover) * $avgDaily);
        // Losses before a transfer could physically arrive are unavoidable.
        $residualUnits = (int) round(max(0.0, min((float) self::TRANSFER_LEAD_DAYS, (float) $leadTime) - $daysOfCover) * $avgDaily);
        $coverable = max(0, $gapUnitsUntilPo - $residualUnits);

        // The transfer protects the coverable gap, capped by the surplus on hand.
        $protectedUnits = max(0, min($coverable, $totalSurplus));
        if ($protectedUnits <= 0) {
            return null;
        }

        return [
            'available'         => true,
            'best_store'        => $best['store_id'],
            'best_surplus'      => $best['surplus'],
            'donor_count'       => count($donors),
            'total_surplus'     => $totalSurplus,
            'transfer_lead'     => self::TRANSFER_LEAD_DAYS,
            'po_lead'           => $leadTime,
            'protected_units'   => $protectedUnits,
            'fully_covered'     => $totalSurplus >= $coverable,
            'protected_revenue' => $unitPrice !== null ? round($protectedUnits * $unitPrice, 2) : null,
            'assumption'        => 'Assumes an intra-network transfer arrives in ~' . self::TRANSFER_LEAD_DAYS
                . ' days — ahead of the ' . $leadTime . '-day supplier lead. Surplus is on-hand above each donor store\'s'
                . ' replenishment target, so releasing it leaves the donor at target. Autnyx recommends only — arrange the'
                . ' transfer in your own systems.',
        ];
    }

    /** @return list<string> */
    private function assumptions(float $onHand, float $avgDaily, float $daysOfCover, ?int $leadTime, ?float $unitPrice, bool $rateFromModel = false): array
    {
        $rateNote = $rateFromModel
            ? 'Sales rate is the replenishment model\'s derived daily rate for this store-SKU (the rate behind its reorder point), used because the investigation carried no explicit rate.'
            : 'Sales continue at the recent daily rate; no substitution or backorder capture.';
        $a = [
            'On-hand ' . $this->n($onHand) . ' units at ' . $this->n($avgDaily) . ' units/day ⇒ ~' . $this->n($daysOfCover) . ' days of cover.',
            $rateNote,
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

    /**
     * The governed replenishment row for this signal's (store, SKU) — one query,
     * reused across on-hand / daily-rate / lead-time / unit-cost fallbacks.
     */
    private function repFor(Investigation $investigation, $anomaly): ?\App\Models\SkuReplenishment
    {
        try {
            return \App\Models\SkuReplenishment::where('tenant_id', $investigation->tenant_id)
                ->where('sku', $anomaly->sku)
                ->when($anomaly->store_id, fn ($q) => $q->where('store_id', $anomaly->store_id))
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function leadTime(Investigation $investigation, $anomaly, $evidence, ?\App\Models\SkuReplenishment $rep = null): ?int
    {
        $rep = $rep ?? $this->repFor($investigation, $anomaly);

        // lead_time_days DEFAULTS to 0 in the schema, so 0 means "not derived",
        // not a real zero-day lead — treat only a positive value as known and
        // fall back to the evidence otherwise (never assume an instant PO).
        if ($rep && $rep->lead_time_days !== null && (float) $rep->lead_time_days > 0) {
            return (int) round((float) $rep->lead_time_days);
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
