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
}
