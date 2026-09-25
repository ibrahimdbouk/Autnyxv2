<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WP7.3 — browsers POST Content-Security-Policy violations here (report-uri).
 * Logged as a warning with the fields that say what was blocked and where;
 * query strings are dropped (they can carry tokens). Rate-limited, no session.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request)
    {
        $raw = (string) $request->getContent();
        if ($raw === '' || strlen($raw) > 16384) {
            return response()->noContent();
        }

        $body   = json_decode($raw, true);
        $report = is_array($body) ? ($body['csp-report'] ?? $body[0]['body'] ?? $body) : null;
        if (! is_array($report)) {
            return response()->noContent();
        }

        $pick = fn (string ...$keys) => collect($keys)->map(fn ($k) => $report[$k] ?? null)->first(fn ($v) => is_scalar($v) && $v !== '');
        $url  = fn ($v) => $v === null ? null : Str::limit(strtok((string) $v, '?#'), 300, '');

        Log::warning('csp.violation', array_filter([
            'directive' => Str::limit((string) $pick('effective-directive', 'violated-directive', 'effectiveDirective'), 80, ''),
            'blocked'   => $url($pick('blocked-uri', 'blockedURL')),
            'document'  => $url($pick('document-uri', 'documentURL')),
            'source'    => $url($pick('source-file', 'sourceFile')),
            'line'      => $pick('line-number', 'lineNumber'),
            'sample'    => Str::limit((string) $pick('script-sample', 'sample'), 80),
            'mode'      => $pick('disposition'),
        ], fn ($v) => $v !== null && $v !== ''));

        return response()->noContent();
    }
}
