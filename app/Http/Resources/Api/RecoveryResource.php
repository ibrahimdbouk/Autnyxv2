<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public representation of a recovery outcome (InvestigationOutcome). */
class RecoveryResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'investigation_id'   => $this->investigation_id,
            'outcome_type'       => $this->outcome_type,
            'outcome_state'      => $this->outcome_state,
            'revenue_at_risk'    => $this->revenue_at_risk,
            'observed_recovery'  => $this->observed_recovery,
            'cost_to_resolve'    => $this->cost_to_resolve,
            'recovery_method'    => $this->recovery_method,
            'attribution_status' => $this->attribution_status,
            'was_false_positive' => (bool) $this->was_false_positive,
            'recorded_at'        => optional($this->recorded_at)->toIso8601String(),
            'created_at'         => optional($this->created_at)->toIso8601String(),
        ];
    }
}
