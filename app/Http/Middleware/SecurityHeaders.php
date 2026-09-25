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
 * referrer policy, a locked-down permissions policy, HSTS over HTTPS, and a
 * nonce-based Content-Security-Policy (enforced by default — see
 * config/autnyx.php › csp_mode and App\Support\Security\Csp).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = strtolower((string) config('autnyx.csp_mode', 'enforce'));
        $nonce = in_array($mode, ['report', 'enforce'], true) ? \App\Support\Security\Csp::start() : null;

        $response = $next($request);
        $headers = $response->headers;

        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), browsing-topics=()');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // WP7.3: nonce-based policy (see App\Support\Security\Csp), enforced
        // by default; CSP_MODE=report is the kill switch. Violations are
        // reported to /csp-report either way.
        if ($nonce !== null) {
            $headerName = $mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
            $headers->set($headerName, \App\Support\Security\Csp::policy($nonce, url('/csp-report')));
        }

        return $response;
    }
}
