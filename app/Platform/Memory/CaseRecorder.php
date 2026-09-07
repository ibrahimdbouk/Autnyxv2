<?php

namespace App\Platform\Memory;

use App\Models\DecisionCase;
use App\Platform\Recommendation\Recommendation;

/**
 * P4.3 — write side of decision memory. Records a recommendation as a case when it
 * is made/adopted (situation + evidence + the recommendation itself), then closes
 * the arc later when the outcome is measured. The realization rate (realized /
 * expected) is the learning signal the case base is built to accumulate.
 */
class CaseRecorder
{
    /**
     * @param  array<string,mixed>  $situation  numeric feature vector for retrieval
     * @param  array<string,mixed>  $evidence
     */
    public function record(
        Recommendation $rec,
        array $situation,
        array $evidence = [],
        string $decision = DecisionCase::DECISION_ADOPTED,
        ?string $actionRef = null,
    ): DecisionCase {
        return DecisionCase::create([
            'tenant_id'      => $rec->tenantId,
            'intent_type'    => $rec->intentType,
            'objective'      => $rec->objective,
            'sku'            => $rec->sku,
            'store_id'       => $rec->storeId,
            'expected_value' => $rec->expectedValue,
            'confidence'     => $rec->confidence,
            'risk'           => $rec->risk,
            'situation'      => $situation,
            'evidence'       => $evidence,
            'recommendation' => $rec->toArray(),
            'decision'       => $decision,
            'action_ref'     => $actionRef,
            'outcome_status' => DecisionCase::OUTCOME_PENDING,
            'occurred_at'    => now(),
        ]);
    }

    /**
     * Close the arc: record the measured outcome and the realization rate.
     *
     * @param  array<string,mixed>  $outcome
     */
    public function recordOutcome(
        DecisionCase $case,
        string $status,
        ?float $realizedValue = null,
        array $outcome = [],
    ): DecisionCase {
        $rate = ($case->expected_value > 0 && $realizedValue !== null)
            ? round($realizedValue / $case->expected_value, 3)
            : null;

        $case->update([
            'outcome_status'   => $status,
            'realized_value'   => $realizedValue,
            'realization_rate' => $rate,
            'outcome'          => $outcome,
            'resolved_at'      => now(),
        ]);

        return $case;
    }
}
