<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * WP7.3 — user and tenant avatars drawn here, as an inline SVG, instead of
 * Filament's default (ui-avatars.com): no third party learns user and tenant
 * names from every page view, and the CSP needs no external image host.
 * Initials are taken by character, not byte (مريم → م, Émile → É).
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        return self::dataUri((string) Filament::getNameForDefaultAvatar($record));
    }

    public static function initials(string $name): string
    {
        $letters = collect(preg_split('/\s+/u', trim($name)) ?: [])
            ->map(fn (string $w) => preg_replace('/^[^\p{L}\p{N}]+/u', '', $w))
            ->filter(fn ($w) => filled($w))
            ->map(fn (string $w) => mb_substr($w, 0, 1))
            ->take(2)
            ->implode('');

        return mb_strtoupper($letters !== '' ? $letters : '?');
    }

    public static function dataUri(string $name): string
    {
        $text = htmlspecialchars(self::initials($name), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            . '<rect width="64" height="64" fill="#4c1d95"/>'
            . '<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#ffffff" '
            . 'font-family="Inter, ui-sans-serif, system-ui, sans-serif" font-size="26" font-weight="600">' . $text . '</text></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
