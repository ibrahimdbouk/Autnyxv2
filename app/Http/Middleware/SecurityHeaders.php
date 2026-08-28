<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 3b — baseline security response headers (defence-in-depth, GDPR/SOC 2/ISO).
 *
 * Complements the edge/CDN headers (Laravel Cloud already sets X-Frame-Options
 * and X-Content-Type-Options), adding the ones it doesn't: a conservative
 * referrer policy, a locked-down permissions policy, and HSTS over HTTPS. No CSP
 * here on purpose — a strict CSP needs per-app tuning to avoid breaking
 * Livewire/Filament assets, so it's a deliberate follow-up.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), browsing-topics=()');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
