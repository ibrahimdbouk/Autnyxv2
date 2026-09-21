<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ApiKey;
use Illuminate\Http\Request;

/**
 * Shared helpers for the public API v1: the authenticated key's tenant, and a
 * bounded per_page. All queries MUST be scoped by tenantId(). See claude/public-api.md.
 */
abstract class BaseApiController
{
    protected function apiKey(Request $request): ApiKey
    {
        return $request->attributes->get('api_key');
    }

    protected function tenantId(Request $request): int
    {
        return (int) $this->apiKey($request)->tenant_id;
    }

    /** Clamp per_page to [1, 100], default 25. */
    protected function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->integer('per_page', 25)));
    }
}
