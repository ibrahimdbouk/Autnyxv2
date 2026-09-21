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
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $token = $this->extractToken($request);

        $key = $token ? ApiKey::findActiveByToken($token) : null;
        if (! $key) {
            return $this->deny('Invalid or missing API key.', 401);
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
