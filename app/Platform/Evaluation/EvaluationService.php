<?php

namespace App\Platform\Evaluation;

use App\Models\EvaluationSnapshot;
use Illuminate\Support\Collection;

/**
 * P4.4 — persist a scorecard so intelligence quality can be trended over time.
 * Computes the current {@see IntelligenceScorecard} and writes one
 * evaluation_snapshots row per dimension (overall + each intent_type + each
 * objective), stamped now. Run it on a schedule (a later wiring step) to build a
 * time series of how the AI is performing.
 */
class EvaluationService
{
    public function __construct(private readonly IntelligenceScorecard $scorecard)
    {
    }

    /**
     * Capture a snapshot of the tenant's current scorecard.
     *
     * @return Collection<int,EvaluationSnapshot>
     */
    public function snapshot(int $tenantId, ?string $period = null): Collection
    {
        $card = $this->scorecard->compute($tenantId, $period);
        $now = now();
        $written = collect();

        $written->push($this->write($tenantId, EvaluationSnapshot::DIM_OVERALL, $card['overall'], $period, $now));

        foreach ($card['by_intent_type'] as $row) {
            $written->push($this->write($tenantId, EvaluationSnapshot::DIM_INTENT_TYPE, $row, $period, $now));
        }
        foreach ($card['by_objective'] as $row) {
            $written->push($this->write($tenantId, EvaluationSnapshot::DIM_OBJECTIVE, $row, $period, $now));
        }

        return $written;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function write(int $tenantId, string $dimension, array $row, ?string $period, $now): EvaluationSnapshot
    {
        return EvaluationSnapshot::create([
            'tenant_id'       => $tenantId,
            'dimension'       => $dimension,
            'dim_key'         => $row['dim_key'] ?? null,
            'period'          => $period,
            'n'               => $row['n'],
            'resolved'        => $row['resolved'],
            'adoption_rate'   => $row['adoption_rate'],
            'success_rate'    => $row['success_rate'],
            'avg_realization' => $row['avg_realization'],
            'avg_confidence'  => $row['avg_confidence'],
            'calibration_gap' => $row['calibration_gap'],
            'captured_at'     => $now,
        ]);
    }
}
