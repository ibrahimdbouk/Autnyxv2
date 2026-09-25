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
            // Deterministic figures (never AI).
            'revenue_at_risk'   => $this->revenue_at_risk,
            'capital_at_risk'   => $this->capital_at_risk,
            'observed_recovery' => $this->observed_recovery,
            'anomaly_count'     => $this->anomaly_count,
            'root_cause'        => $this->root_cause_tier ? [
                'rule' => $this->root_cause_rule,
                'tier' => $this->root_cause_tier,   // corroborated | likely | correlated | single
            ] : null,
            // WP5.4: everything a model wrote, nested and labelled as such.
            'ai'                => $this->ai_generated_at ? [
                'headline'         => $this->ai_headline,
                'summary'          => $this->ai_summary,
                'root_cause_text'  => $this->ai_root_cause,
                'recommended_action' => $this->ai_recommended_action,
                'revenue_estimate' => $this->ai_revenue_estimate,
                'confidence'       => $this->ai_confidence,
                'model'            => $this->ai_model,
                'generated_at'     => optional($this->ai_generated_at)->toIso8601String(),
            ] : null,
            'opened_at'         => optional($this->opened_at)->toIso8601String(),
            'resolved_at'       => optional($this->resolved_at)->toIso8601String(),
            'created_at'        => optional($this->created_at)->toIso8601String(),
            'updated_at'        => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
