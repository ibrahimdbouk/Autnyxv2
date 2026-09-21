<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\RecoveryResource;
use App\Models\InvestigationOutcome;
use Illuminate\Http\Request;

class RecoveryController extends BaseApiController
{
    public function index(Request $request)
    {
        $tenantId = $this->tenantId($request);

        $items = InvestigationOutcome::where('tenant_id', $tenantId)
            ->when($request->filled('outcome_state'), fn ($q) => $q->where('outcome_state', $request->string('outcome_state')))
            ->when($request->filled('since'), fn ($q) => $q->where('created_at', '>=', $request->date('since')))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return RecoveryResource::collection($items);
    }
}
