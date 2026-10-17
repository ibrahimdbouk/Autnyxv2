<?php

namespace App\Support\Apps;

use App\Filament\Pages\ActionCenter;
use App\Filament\Pages\ActionQueue;
use App\Filament\Pages\DailyBriefing;
use App\Filament\Pages\FollowUps;
use App\Filament\Pages\InvestigationsByStore;
use App\Filament\Pages\PerformanceDrivers;
use App\Filament\Pages\SupplierPrep;
use App\Filament\Pages\SupplierScorecard;
use App\Filament\Pages\WatchedInvestigations;
use App\Filament\Pages\WeeklyBriefing;
use App\Filament\Resources\AnomalySettingResource;
use App\Filament\Resources\CustomMetricResource;
use App\Filament\Resources\CustomRuleResource;
use App\Filament\Resources\InvestigationResource;
use App\Filament\Resources\PlanningExceptionResource;
use App\Filament\Resources\ReplenishmentResource;
use App\Filament\Resources\SuppressionResource;
use Filament\Navigation\NavigationItem;

/**
 * v2.5 — the Root Cause menu, shortened: screens that belong together sit
 * behind one menu entry, and the screens themselves show as tabs along the top
 * of each (Filament's own sub-navigation). Their URLs, permissions and screen
 * keys do not change: a set is only how they are shown.
 *
 *   the menu entry   the set's label, linking to the first screen of the set
 *                    the person may open; it carries that screen's badge (or
 *                    the first one in the set that has a badge)
 *   the tabs         every screen of the set the person may open, each with
 *                    its own badge; shown once there are two or more
 *
 * A screen joins a set with `const MENU_SET = '<key>'` and the InMenuSet trait
 * (a Resource also puts ShowsMenuSetTabs on its list page).
 */
final class MenuSets
{
    /** @return array<string, array{label: string, members: list<class-string>}> in menu order within the group */
    public static function all(): array
    {
        return [
            'briefings'      => ['label' => 'Briefings', 'members' => [DailyBriefing::class, WeeklyBriefing::class, PerformanceDrivers::class]],
            'investigations' => ['label' => 'Investigations', 'members' => [InvestigationResource::class, InvestigationsByStore::class, WatchedInvestigations::class]],
            'actions'        => ['label' => 'Actions', 'members' => [ActionCenter::class, ActionQueue::class, FollowUps::class]],
            'replenishment'  => ['label' => 'Replenishment', 'members' => [ReplenishmentResource::class, PlanningExceptionResource::class]],
            'suppliers'      => ['label' => 'Suppliers', 'members' => [SupplierScorecard::class, SupplierPrep::class]],
            'rules'          => ['label' => 'Detection settings', 'members' => [AnomalySettingResource::class, SuppressionResource::class, CustomRuleResource::class, CustomMetricResource::class]],
        ];
    }

    /** Forget what was worked out for this request (the memo lives in the container, so a new request starts clean). */
    public static function flush(): void
    {
        app()->forgetInstance('autnyx.menusets');
    }

    /** @return list<class-string> the set's screens the current person may open, in order */
    public static function open(string $set): array
    {
        $key = $set . ':' . (auth()->id() ?? 0) . ':' . (\Filament\Facades\Filament::getTenant()?->getKey() ?? 0);
        $memo = app()->bound('autnyx.menusets') ? app('autnyx.menusets') : new \ArrayObject();
        app()->instance('autnyx.menusets', $memo);
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        $out = [];
        foreach (self::all()[$set]['members'] ?? [] as $class) {
            try {
                if ($class::shouldRegisterNavigation() && $class::canAccess()) {
                    $out[] = $class;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $memo[$key] = $out;
    }

    /** The screen whose menu entry stands for the set (null = the person may open none). */
    public static function lead(string $set): ?string
    {
        return self::open($set)[0] ?? null;
    }

    public static function label(string $set): string
    {
        return self::all()[$set]['label'] ?? $set;
    }

    /** Whether the current request is on one of the set's screens. */
    public static function isActive(string $set): bool
    {
        foreach (self::all()[$set]['members'] ?? [] as $class) {
            if (request()->routeIs($class::getNavigationItemActiveRoutePattern())) {
                return true;
            }
        }

        return false;
    }

    public static function urlOf(string $class): string
    {
        return is_subclass_of($class, \Filament\Resources\Resource::class) ? $class::getUrl('index') : $class::getUrl();
    }

    /** @return array{0: mixed, 1: ?string, 2: ?string} badge, colour, tooltip */
    public static function badgeOf(string $class): array
    {
        try {
            return [$class::getNavigationBadge(), $class::getNavigationBadgeColor(), $class::getNavigationBadgeTooltip()];
        } catch (\Throwable $e) {
            report($e);

            return [null, null, null];
        }
    }

    /**
     * The tabs for a screen of the set ($current = the screen being shown).
     *
     * @return list<NavigationItem>
     */
    public static function tabs(string $set, string $current): array
    {
        $open = self::open($set);
        if (count($open) < 2) {
            return [];
        }
        $items = [];
        foreach ($open as $i => $class) {
            [$badge, $color, $tip] = self::badgeOf($class);
            $item = NavigationItem::make((string) $class::getNavigationLabel())
                ->url(self::urlOf($class))
                ->isActiveWhen(fn () => $class === $current)
                ->sort($i);
            if (filled($badge)) {
                $item->badge((string) $badge, $color)->badgeTooltip($tip);
            }
            $items[] = $item;
        }

        return $items;
    }
}
