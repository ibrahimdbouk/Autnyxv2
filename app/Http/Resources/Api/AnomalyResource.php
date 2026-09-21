<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public representation of an Anomaly. */
class AnomalyResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'investigation_id'     => $this->investigation_id,
            'rule_type'            => $this->rule_type,
            'severity'             => $this->severity,
            'sku'                  => $this->sku,
            'store_id'             => $this->store_id,
            'description'          => $this->description,
            'lifecycle_state'      => $this->lifecycle_state,
            'investigation_status' => $this->investigation_status,
            'recommendation_gate'  => $this->ai_recommendation_gate,
            'is_false_positive'    => (bool) $this->is_false_positive,
            'detected_at'          => optional($this->detected_at)->toIso8601String(),
            'first_seen_at'        => optional($this->first_seen_at)->toIso8601String(),
            'last_seen_at'         => optional($this->last_seen_at)->toIso8601String(),
            'created_at'           => optional($this->created_at)->toIso8601String(),
        ];
    }
}
