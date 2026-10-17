<?php

namespace App\Filament\Concerns;

use App\Support\Apps\MenuSets;
use Filament\Pages\Enums\SubNavigationPosition;

/**
 * v2.5 — the screens of a menu set as tabs along the top of the page
 * (Filament's own sub-navigation). On a Page the set is its own MENU_SET; on
 * a Resource's list page, the resource's.
 */
trait ShowsMenuSetTabs
{
    private static function menuSetOwner(): string
    {
        return method_exists(static::class, 'getResource') ? static::getResource() : static::class;
    }

    public function getSubNavigation(): array
    {
        $owner = self::menuSetOwner();
        $set = defined($owner . '::MENU_SET') ? constant($owner . '::MENU_SET') : null;

        return $set ? MenuSets::tabs($set, $owner) : parent::getSubNavigation();
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }
}
