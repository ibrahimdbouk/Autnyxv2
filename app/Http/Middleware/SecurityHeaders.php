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
 * Content-Security-Policy (Report-Only by default — see config/autnyx.php ›
 * csp_mode). The CSP allows what a Filament/Livewire/Alpine app needs while
 * locking down framing, base-uri, form-action, and objects.
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

        $mode = strtolower((string) config('autnyx.csp_mode', 'report'));
        if ($mode === 'report' || $mode === 'enforce') {
            $headerName = $mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
            $headers->set($headerName, $this->csp());
        }

        return $response;
    }

    private function csp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            // Filament/Livewire/Alpine need inline + eval; Chart.js from cdnjs.
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }
}
