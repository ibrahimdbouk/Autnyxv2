<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\DataHealthResource;
use App\Models\DataHealthSnapshot;
use Illuminate\Http\Request;

class DataHealthController extends BaseApiController
{
    /** The latest snapshot per dataset for the tenant. */
    public function index(Request $request)
    {
        $latest = DataHealthSnapshot::where('tenant_id', $this->tenantId($request))
            ->orderByDesc('computed_at')
            ->orderByDesc('id')
            ->get()
            ->unique('dataset')
            ->values();

        return DataHealthResource::collection($latest);
    }
}
