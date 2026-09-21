<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public representation of an Investigation. */
class InvestigationResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'title'             => $this->title,
            'status'            => $this->status,
            'priority'          => $this->priority,
            'primary_sku'       => $this->primary_sku,
            'primary_store_id'  => $this->primary_store_id,
            'revenue_at_risk'   => $this->revenue_at_risk,
            'observed_recovery' => $this->observed_recovery,
            'anomaly_count'     => $this->anomaly_count,
            'headline'          => $this->ai_headline,
            'summary'           => $this->ai_summary,
            'confidence'        => $this->ai_confidence,
            'opened_at'         => optional($this->opened_at)->toIso8601String(),
            'resolved_at'       => optional($this->resolved_at)->toIso8601String(),
            'created_at'        => optional($this->created_at)->toIso8601String(),
            'updated_at'        => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
