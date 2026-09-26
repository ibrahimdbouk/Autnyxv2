<?php

namespace App\Support;

/**
 * Brand assets. The logo is optional at the code level: drop a file at
 * public/images/autnyx-logo.(svg|png|webp|jpg) and it appears in the top bar and
 * on the sign-in page automatically — no redeploy of code needed. Until then the
 * panel falls back to the brand name text.
 */
final class Branding
{
    /** Absolute URL to the brand logo if one is present, else null. */
    public static function logoUrl(): ?string
    {
        foreach (['svg', 'png', 'webp', 'jpg'] as $ext) {
            if (is_file(public_path("images/autnyx-logo.{$ext}"))) {
                return asset("images/autnyx-logo.{$ext}");
            }
        }

        return null;
    }

    /** Cache-buster for the global stylesheet (mtime, so edits invalidate). */
    public static function cssVersion(): string
    {
        $path = public_path('css/autnyx-ui.css');

        return is_file($path) ? (string) filemtime($path) : '1';
    }

    /**
     * Design tokens + the shared UI behaviours (WP7.3), for the head of every
     * panel page. The script runs once per full page load (data-navigate-once)
     * and carries the request's CSP nonce.
     */
    public static function headAssets(): string
    {
        $js    = public_path('js/autnyx-ui.js');
        $jsVer = is_file($js) ? (string) filemtime($js) : '1';
        $nonce = \Illuminate\Support\Facades\Vite::cspNonce();

        return self::currencyFont()
            . '<link rel="stylesheet" href="' . asset('css/autnyx-ui.css') . '?v=' . self::cssVersion() . '">'
            . '<script src="' . asset('js/autnyx-ui.js') . '?v=' . $jsVer . '"'
            . ($nonce ? ' nonce="' . e($nonce) . '"' : '') . ' data-navigate-once></script>';
    }

    /**
     * The new UAE dirham (U+20C3) and Saudi riyal (U+20C1) signs are too recent
     * for system fonts. A tiny self-hosted font family, limited by
     * unicode-range to those two glyphs, is put first in the panel's font
     * stack, so the signs render anywhere on screen (and in Chart.js, which
     * reads the same stack) while every other character keeps the panel font.
     */
    public static function currencyFont(): string
    {
        $base = asset('vendor/currency');
        $panelFont = str_replace(["'", '"', '<', '>', ';', '}'], '', (string) (filament()->getFontFamily() ?? 'Inter'));

        return '<link rel="preload" href="' . $base . '/dirham.woff2" as="font" type="font/woff2" crossorigin>'
            . '<style>'
            // Chrome picks one weight band of a family before looking at
            // unicode-range, so each band carries both signs.
            . "@font-face{font-family:'AxCurrency';src:url('{$base}/dirham.woff2') format('woff2');unicode-range:U+20C3;font-weight:100 550;size-adjust:88%;font-display:block}"
            . "@font-face{font-family:'AxCurrency';src:url('{$base}/dirham.woff2') format('woff2');unicode-range:U+20C3;font-weight:551 900;size-adjust:88%;font-display:block}"
            . "@font-face{font-family:'AxCurrency';src:url('{$base}/riyal-regular.woff2') format('woff2');unicode-range:U+20C1;font-weight:100 550;font-display:block}"
            . "@font-face{font-family:'AxCurrency';src:url('{$base}/riyal-bold.woff2') format('woff2');unicode-range:U+20C1;font-weight:551 900;font-display:block}"
            . ":root:root{--font-family:'AxCurrency','{$panelFont}'}"
            . '</style>';
    }
}
