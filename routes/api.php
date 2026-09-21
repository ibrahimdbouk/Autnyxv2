<?php

use App\Http\Controllers\Api\V1\AnomalyController;
use App\Http\Controllers\Api\V1\DataHealthController;
use App\Http\Controllers\Api\V1\IngestController;
use App\Http\Controllers\Api\V1\InvestigationController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\RecoveryController;
use App\Models\ApiKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/*
 * Public API v1 — see claude/public-api.md. API-key auth (Bearer / X-Api-Key),
 * per-key scope enforcement, and per-key rate limiting. All data is tenant-scoped
 * to the key. These routes are prefixed with /api by the framework → /api/v1/...
 */

RateLimiter::for('publicapi', function (Request $request) {
    $key = $request->attributes->get('api_key');
    $by  = $key instanceof ApiKey ? 'key:' . $key->id : 'ip:' . $request->ip();

    return Limit::perMinute(120)->by($by);
});

Route::prefix('v1')->group(function () {
    // Discovery — unauthenticated.
    Route::get('openapi.json', [OpenApiController::class, 'spec']);

    Route::middleware(['api.key:' . ApiKey::SCOPE_READ_INVESTIGATIONS, 'throttle:publicapi'])->group(function () {
        Route::get('investigations', [InvestigationController::class, 'index']);
        Route::get('investigations/{id}', [InvestigationController::class, 'show'])->whereNumber('id');
    });

    Route::middleware(['api.key:' . ApiKey::SCOPE_READ_ANOMALIES, 'throttle:publicapi'])->group(function () {
        Route::get('anomalies', [AnomalyController::class, 'index']);
        Route::get('anomalies/{id}', [AnomalyController::class, 'show'])->whereNumber('id');
    });

    Route::middleware(['api.key:' . ApiKey::SCOPE_READ_RECOVERIES, 'throttle:publicapi'])
        ->get('recoveries', [RecoveryController::class, 'index']);

    Route::middleware(['api.key:' . ApiKey::SCOPE_READ_DATA_HEALTH, 'throttle:publicapi'])
        ->get('data-health', [DataHealthController::class, 'index']);

    Route::middleware(['api.key:' . ApiKey::SCOPE_WRITE_INGEST, 'throttle:publicapi'])
        ->post('ingest', [IngestController::class, 'store']);
});
