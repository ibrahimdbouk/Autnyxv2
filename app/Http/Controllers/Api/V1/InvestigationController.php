<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\InvestigationResource;
use App\Models\Investigation;
use Illuminate\Http\Request;

class InvestigationController extends BaseApiController
{
    public function index(Request $request)
    {
        $tenantId = $this->tenantId($request);

        $items = Investigation::where('tenant_id', $tenantId)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->string('priority')))
            ->when($request->filled('since'), fn ($q) => $q->where('created_at', '>=', $request->date('since')))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return InvestigationResource::collection($items);
    }

    public function show(Request $request, int $id)
    {
        $investigation = Investigation::where('tenant_id', $this->tenantId($request))->findOrFail($id);

        return new InvestigationResource($investigation);
    }
}
