<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\AnomalyResource;
use App\Models\Anomaly;
use Illuminate\Http\Request;

class AnomalyController extends BaseApiController
{
    public function index(Request $request)
    {
        $tenantId = $this->tenantId($request);

        $items = Anomaly::where('tenant_id', $tenantId)
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->string('severity')))
            ->when($request->filled('lifecycle_state'), fn ($q) => $q->where('lifecycle_state', $request->string('lifecycle_state')))
            ->when($request->filled('rule_type'), fn ($q) => $q->where('rule_type', $request->string('rule_type')))
            ->when($request->filled('since'), fn ($q) => $q->where('created_at', '>=', $request->date('since')))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return AnomalyResource::collection($items);
    }

    public function show(Request $request, int $id)
    {
        $anomaly = Anomaly::where('tenant_id', $this->tenantId($request))->findOrFail($id);

        return new AnomalyResource($anomaly);
    }
}
