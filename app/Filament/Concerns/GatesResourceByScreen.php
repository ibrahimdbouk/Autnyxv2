<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * 1a — gate a Filament Resource behind a screen-visibility key.
 *
 * The consuming Resource declares `const SCREEN_KEY = '<key>'` (a key from
 * App\Support\Screens\ScreenRegistry). Admins always pass (see everything);
 * non-admins pass only when the key is in their `visible_screens`. Gates the
 * list, individual record view, and the navigation entry — enough to keep a
 * screen out of a user's reach without touching create/edit/delete semantics,
 * which these read-centric resources already lock down.
 */
trait GatesResourceByScreen
{
    public static function canViewAny(): bool
    {
        return static::userCanSeeScreen();
    }

    public static function canView(Model $record): bool
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
