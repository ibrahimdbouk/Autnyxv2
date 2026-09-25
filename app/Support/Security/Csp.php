<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\Vite;

/**
 * WP7.3 (audit L12, moved from W2) — a nonce-based Content-Security-Policy.
 *
 * Every script the app itself writes into a template carries the request's
 * nonce; nothing else runs. A script injected through data (a product name,
 * an import cell, an AI narration) has no nonce and is refused, and inline
 * event handlers are refused outright (script-src-attr 'none').
 *
 * How the nonce gets onto the scripts:
 *   • Livewire / Filament core assets: Livewire reads Vite::cspNonce().
 *   • Every `<script` tag written in a Blade template (ours and Filament's)
 *     gets `nonce="…"` from a Blade precompiler — at COMPILE time, on the
 *     template source only. Rendered data never passes through it, so data
 *     can never gain a nonce.
 *   • SPA navigation: Livewire rewrites the nonces of the fetched page to the
 *     current page's nonce before running its scripts.
 *
 * Still allowed, deliberately: 'unsafe-eval' (Alpine evaluates x-data /
 * x-on expressions with Function; Filament has no CSP-safe build) and inline
 * styles. Same-origin scripts load without a nonce ('self').
 */
final class Csp
{
    /** Start the request's nonce (before any view renders). */
    public static function start(): string
    {
        return Vite::useCspNonce();
    }

    public static function nonce(): ?string
    {
        return Vite::cspNonce();
    }

    public static function policy(?string $nonce, ?string $reportUri = null): string
    {
        $storage = self::uploadOrigins();

        $directives = [
            "default-src 'self'",
            "script-src 'self'" . ($nonce ? " 'nonce-{$nonce}'" : '') . " 'unsafe-eval'",
            "script-src-attr 'none'",
            "style-src 'self' 'unsafe-inline'",
            trim("img-src 'self' data: blob: " . implode(' ', $storage)),
            "font-src 'self' data:",
            trim("connect-src 'self' " . implode(' ', $storage)),
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];
        if ($reportUri) {
            $directives[] = "report-uri {$reportUri}";
        }

        return implode('; ', $directives);
    }

    /**
     * Livewire uploads straight to S3-compatible storage with a signed URL
     * when its temporary-upload disk is S3, and previews from there: allow
     * that one origin, nothing wider.
     *
     * @return array<int,string>
     */
    public static function uploadOrigins(): array
    {
        $disk = config('livewire.temporary_file_upload.disk') ?: config('filesystems.default');
        $cfg  = config("filesystems.disks.{$disk}");
        if (! is_array($cfg) || ($cfg['driver'] ?? null) !== 's3') {
            return [];
        }

        $origins = [];
        foreach (['url', 'endpoint'] as $key) {
            if (! empty($cfg[$key]) && ($o = self::origin((string) $cfg[$key]))) {
                // Virtual-hosted buckets are addressed as <bucket>.<host>.
                $origins[] = $o;
                if (! empty($cfg['bucket']) && empty($cfg['use_path_style_endpoint'])) {
                    $origins[] = preg_replace('#^(https?://)#', '$1' . $cfg['bucket'] . '.', $o);
                }
            }
        }
        if (! $origins && ! empty($cfg['bucket'])) {
            $region    = $cfg['region'] ?? 'us-east-1';
            $origins[] = "https://{$cfg['bucket']}.s3.{$region}.amazonaws.com";
            $origins[] = "https://{$cfg['bucket']}.s3.amazonaws.com";
        }

        return array_values(array_unique($origins));
    }

    private static function origin(string $url): ?string
    {
        $p = parse_url($url);

        return isset($p['scheme'], $p['host']) && in_array($p['scheme'], ['http', 'https'], true)
            ? $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '')
            : null;
    }

    /**
     * Blade precompiler: add the nonce to every `<script` tag written at the
     * start of a template line that has none. (Every script tag in our and
     * the vendors' templates starts a line; a `<script` inside a string or
     * mid-line is left alone.)
     */
    public static function precompile(string $template): string
    {
        if (! str_contains($template, '<script')) {
            return $template;
        }

        return preg_replace(
            '/^(\h*)<script\b(?![^>]*\bnonce=)/mi',
            '$1<script nonce="{{ \\App\\Support\\Security\\Csp::nonce() }}"',
            $template
        );
    }
}
