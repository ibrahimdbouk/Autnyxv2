<?php

namespace App\Filament\Concerns;

/**
 * 1a — gate a Filament Page behind a screen-visibility key.
 *
 * The consuming Page declares `const SCREEN_KEY = '<key>'` (a key from
 * App\Support\Screens\ScreenRegistry). Admins always pass; non-admins pass only
 * when the key is in their `visible_screens`. Gates both direct access and the
 * navigation entry.
 */
trait GatesPageByScreen
{
    public static function canAccess(): bool
    {
        return static::userCanSeeScreen();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::userCanSeeScreen();
    }

    protected static function userCanSeeScreen(): bool
    {
        return auth()->user()?->canSeeScreen(static::SCREEN_KEY) ?? false;
    }
}
