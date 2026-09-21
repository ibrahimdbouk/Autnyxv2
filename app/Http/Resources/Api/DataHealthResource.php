<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public representation of a DataHealthSnapshot. */
class DataHealthResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'dataset'          => $this->dataset,
            'status'           => $this->status,
            'score'            => $this->score,
            'completeness_pct' => $this->completeness_pct,
            'validity_pct'     => $this->validity_pct,
            'freshness_hours'  => $this->freshness_hours,
            'records_received' => $this->records_received,
            'records_accepted' => $this->records_accepted,
            'records_rejected' => $this->records_rejected,
            'last_ingested_at' => optional($this->last_ingested_at)->toIso8601String(),
            'computed_at'      => optional($this->computed_at)->toIso8601String(),
        ];
    }
}
