<?php

namespace App\Support\Apps;

use App\Filament\Dashboards\AssortmentDashboard;
use App\Filament\Dashboards\RootCauseDashboard;
use App\Filament\Dashboards\TasksDashboard;
use App\Models\Tenant;
use App\Models\User;
use Filament\Navigation\NavigationGroup;

/**
 * The apps on the platform, in display order — the single source for the
 * Dashboard's app tabs and the left menu's layout.
 *
 *   Dashboard                      (one item, always first — ungrouped)
 *   Root Cause / Assortment / Tasks (one group per app the tenant holds)
 *   Data · Data Quality · Administration · Settings   (shared by every app)
 *
 * Which app a screen belongs to is decided by AppGate::appFor(); a screen of an
 * app must sit in that app's group, a shared screen in a shared group. The
 * NavigationLayoutTest holds every page and resource to that.
 */
final class AppRegistry
{
    public const GROUP_ROOT_CAUSE = 'Root Cause';
    public const GROUP_ASSORTMENT = 'Assortment';
    public const GROUP_TASKS      = 'Tasks';

    /** Groups every app shares, in menu order (after the app groups). */
    public const SHARED_GROUPS = ['Data', 'Data Quality', 'Administration', 'Settings'];

    /**
     * key => label (tab + group), menu group, icon, dashboard view class.
     *
     * @return array<string,array{label:string,group:string,icon:string,dashboard:class-string}>
     */
    public static function apps(): array
    {
        return [
            Tenant::APP_ROOT_CAUSE => [
                'label'     => 'Root Cause',
                'group'     => self::GROUP_ROOT_CAUSE,
                'icon'      => 'heroicon-o-magnifying-glass-circle',
                'dashboard' => RootCauseDashboard::class,
            ],
            Tenant::APP_ASSORTMENT => [
                'label'     => 'Assortment',
                'group'     => self::GROUP_ASSORTMENT,
                'icon'      => 'heroicon-o-squares-2x2',
                'dashboard' => AssortmentDashboard::class,
            ],
            Tenant::APP_TASK_EXECUTION => [
                'label'     => 'Tasks',
                'group'     => self::GROUP_TASKS,
                'icon'      => 'heroicon-o-clipboard-document-list',
                'dashboard' => TasksDashboard::class,
            ],
        ];
    }

    public static function has(?string $app): bool
    {
        return $app !== null && array_key_exists($app, self::apps());
    }

    public static function label(string $app): string
    {
        return self::apps()[$app]['label'] ?? $app;
    }

    public static function groupFor(string $app): ?string
    {
        return self::apps()[$app]['group'] ?? null;
    }

    /** @return array<int,string> the app group labels, in order */
    public static function appGroups(): array
    {
        return array_values(array_map(fn (array $a) => $a['group'], self::apps()));
    }

    /** @return array<int,string> every group label the tenant menu may use, in order */
    public static function allGroups(): array
    {
        return [...self::appGroups(), ...self::SHARED_GROUPS];
    }

    /**
     * The panel's group order. App groups collapse and expand by themselves
     * (see sidebarScript()); shared groups are collapsible, and closed on a
     * user's first visit so the menu opens on the app's own work.
     *
     * @return array<int,NavigationGroup>
     */
    public static function navigationGroups(): array
    {
        $groups = array_map(fn (string $label) => NavigationGroup::make($label)->collapsible(), self::appGroups());
        foreach (self::SHARED_GROUPS as $label) {
            $groups[] = NavigationGroup::make($label)->collapsible()->collapsed();
        }

        return $groups;
    }

    /**
     * The apps this tenant holds, in registry order.
     *
     * @return array<int,string>
     */
    public static function forTenant(?Tenant $tenant): array
    {
        $held = $tenant instanceof Tenant ? $tenant->enabledApps() : Tenant::DEFAULT_APPS;

        return array_values(array_filter(array_keys(self::apps()), fn (string $k) => in_array($k, $held, true)));
    }

    /**
     * The tab a person lands on: their last one if still available, else by
     * their role (store staff → Tasks), else the first app the tenant holds.
     *
     * @param  array<int,string>  $available
     */
    public static function defaultFor(?User $user, array $available): ?string
    {
        if ($available === []) {
            return null;
        }
        $last = $user ? self::preference($user, 'last_dashboard') : null;
        if (is_string($last) && in_array($last, $available, true)) {
            return $last;
        }
        $byRole = match ($user?->org_role) {
            User::ORG_STORE_MANAGER, User::ORG_ASSOCIATE => Tenant::APP_TASK_EXECUTION,
            default                                      => Tenant::APP_ROOT_CAUSE,
        };

        return in_array($byRole, $available, true) ? $byRole : $available[0];
    }

    /** Remember the person's last dashboard tab (one small write, only on change). */
    public static function rememberDashboard(?User $user, string $app): void
    {
        if (! $user || self::preference($user, 'last_dashboard') === $app) {
            return;
        }
        $prefs = is_array($user->preferences) ? $user->preferences : [];
        $prefs['last_dashboard'] = $app;
        try {
            $user->forceFill(['preferences' => $prefs])->saveQuietly();
        } catch (\Throwable $e) {
            report($e); // a preference is never worth failing the page over
        }
    }

    private static function preference(User $user, string $key): mixed
    {
        return is_array($user->preferences) ? ($user->preferences[$key] ?? null) : null;
    }

    /**
     * The app the current request's page belongs to — on the Dashboard, the
     * app of the open tab; null on a shared page.
     */
    public static function currentApp(): ?string
    {
        try {
            $class = request()->route()?->getControllerClass();
        } catch (\Throwable) {
            return null;
        }
        if ($class === \App\Filament\Pages\Dashboard::class) {
            return \App\Filament\Pages\Dashboard::resolveApp(request()->query('app'));
        }

        return AppGate::appFor($class);
    }

    /**
     * Sidebar behaviour, rendered at the end of the sidebar on every page: the
     * group of the app the current page belongs to opens and the other app
     * groups close. On a shared page the app groups stay as the person left them.
     */
    public static function sidebarScript(?string $currentApp): string
    {
        $current = self::groupFor((string) $currentApp);
        if ($current === null) {
            return '';
        }
        $others = array_values(array_diff(self::appGroups(), [$current]));
        $payload = json_encode(['open' => $current, 'close' => $others], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $nonce   = \App\Support\Security\Csp::nonce();
        $attr    = $nonce ? ' nonce="' . e($nonce) . '"' : '';

        return <<<HTML
<script{$attr}>
(function () {
    var cfg = {$payload};
    var list;
    try { list = JSON.parse(localStorage.getItem('collapsedGroups')) || []; } catch (e) { list = []; }
    list = list.filter(function (g) { return g !== cfg.open; });
    cfg.close.forEach(function (g) { if (list.indexOf(g) === -1) { list.push(g); } });
    try { localStorage.setItem('collapsedGroups', JSON.stringify(list)); } catch (e) {}
    var store = window.Alpine && window.Alpine.store && window.Alpine.store('sidebar');
    if (store && Array.isArray(store.collapsedGroups)) { store.collapsedGroups = list; }
    document.querySelectorAll('.fi-sidebar-group').forEach(function (group) {
        var items = group.querySelector('.fi-sidebar-group-items');
        if (!items) { return; }
        var closed = list.indexOf(group.dataset.groupLabel) !== -1;
        if (group.dataset.groupLabel === cfg.open || cfg.close.indexOf(group.dataset.groupLabel) !== -1) {
            items.style.display = closed ? 'none' : '';
            group.classList.toggle('fi-collapsed', closed);
        }
    });
})();
</script>
HTML;
    }
}
