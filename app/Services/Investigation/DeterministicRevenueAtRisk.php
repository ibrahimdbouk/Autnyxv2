<?php

namespace App\Services\Investigation;

use App\Models\Investigation;

/**
 * The single, deterministic definition of an investigation's revenue at risk:
 * Σ revenue_impact recorded by the detection engine on its member anomalies.
 *
 * WP1.1 (audit C1): this is the ONLY writer of investigations.revenue_at_risk.
 * AI output never feeds it (see InvestigationNarratorService → ai_revenue_estimate).
 */
class DeterministicRevenueAtRisk
{
    public function compute(Investigation $investigation): float
    {
        $total = 0.0;
        foreach ($investigation->anomalies()->get(['context']) as $a) {
            $total += (float) ($a->context['revenue_impact'] ?? 0);
        }

        return round($total, 2);
    }

    public function sync(Investigation $investigation): float
    {
        $total = $this->compute($investigation);
        $investigation->update(['revenue_at_risk' => $total]);

        return $total;
    }
}
