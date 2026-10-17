<?php

namespace App\Filament\Concerns;

use App\Support\Apps\MenuSets;
use Filament\Navigation\NavigationItem;

/**
 * v2.5 — a screen in one of the Root Cause menu sets (App\Support\Apps\MenuSets):
 * only the set's first open screen has a menu entry, named after the set and
 * lit on any of its screens. The class declares `const MENU_SET = '<key>'`.
 * On a Page this also shows the set's screens as tabs (ShowsMenuSetTabs).
 */
trait InMenuSet
{
    /** @return array<NavigationItem> */
    public static function getNavigationItems(): array
    {
        $set = static::MENU_SET;
        if (MenuSets::lead($set) !== static::class) {
            return [];
        }
        $badgeFrom = static::class;
        foreach (MenuSets::open($set) as $class) {
            if (filled(MenuSets::badgeOf($class)[0])) {
                $badgeFrom = $class;
                break;
            }
        }
        [$badge, $color, $tip] = MenuSets::badgeOf($badgeFrom);

        return array_map(function (NavigationItem $item) use ($set, $badge, $color, $tip) {
            $item->label(MenuSets::label($set))->isActiveWhen(fn () => MenuSets::isActive($set));
            if (filled($badge)) {
                $item->badge((string) $badge, $color)->badgeTooltip($tip);
            }

            return $item;
        }, parent::getNavigationItems());
    }
}
