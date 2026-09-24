<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a public-API request by API key and enforces a required scope.
 *
 * Token is read from `Authorization: Bearer <token>` or the `X-Api-Key` header.
 * On success the resolved ApiKey is stashed on the request (`api_key`) for
 * controllers to scope by tenant; failures return a JSON error. Usage:
 *   Route::middleware('api.key:read:investigations')
 *
 * See claude/public-api.md.
 */
class AuthenticateApiKey
{
    /** WP2.3: failed-auth attempts allowed per IP per minute before 429. */
    private const MAX_FAILED_PER_MINUTE = 20;

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        // WP2.3 (audit M10): throttle BEFORE authenticating, so key guessing is
        // rate-limited too (the per-key limiter only applies to valid keys).
        $failKey = 'api-auth-fail:' . $request->ip();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($failKey, self::MAX_FAILED_PER_MINUTE)) {
            return response()->json(['error' => 'too_many_requests', 'message' => 'Too many failed authentication attempts. Try again later.'], 429);
        }

        $token = $this->extractToken($request);

        $key = $token ? ApiKey::findActiveByToken($token) : null;
        if (! $key) {
            \Illuminate\Support\Facades\RateLimiter::hit($failKey, 60);

            return $this->deny('Invalid or missing API key.', 401);
        }

        // WP2.3 (audit M10): a suspended or offboarded organisation's keys stop working.
        $tenant = $key->tenant;
        if ($tenant === null || ($tenant->status ?? \App\Models\Tenant::STATUS_ACTIVE) !== \App\Models\Tenant::STATUS_ACTIVE) {
            return $this->deny('This organisation\'s API access is suspended.', 403);
        }

        if ($scope !== null && ! $key->hasScope($scope)) {
            return $this->deny("This key is missing the required scope: {$scope}.", 403);
        }

        $key->touchUsed();
        $request->attributes->set('api_key', $key);

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $bearer = $request->bearerToken();
        if ($bearer) {
            return trim($bearer);
        }

        $header = $request->header('X-Api-Key');

        return $header ? trim($header) : null;
    }

    private function deny(string $message, int $status): Response
    {
        return response()->json([
            'error'   => $status === 401 ? 'unauthorized' : 'forbidden',
            'message' => $message,
        ], $status);
    }
}
