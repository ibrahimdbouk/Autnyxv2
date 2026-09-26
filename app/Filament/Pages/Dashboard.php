<?php

namespace App\Filament\Pages;

use App\Filament\Dashboards\AppDashboard;
use App\Support\Apps\AppRegistry;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Livewire\Attributes\Url;

/**
 * The one Dashboard — the first item of the menu for every app. A switch across
 * the top moves between each app's own dashboard (Root Cause · Assortment ·
 * Tasks); tabs are the apps the tenant holds, and a tab never shows another
 * app's numbers. The tab lives in the URL (?app=…) so it can be bookmarked and
 * shared; the person's last tab is remembered.
 */
class Dashboard extends BaseDashboard
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = -100;

    protected string $view = 'filament.pages.dashboard';

    #[Url(as: 'app')]
    public ?string $app = null;

    public function getTitle(): string
    {
        return 'Dashboard';
    }

    /** The custom view renders everything itself; no widget grid. */
    public function getWidgets(): array
    {
        return [];
    }

    public function mount(): void
    {
        $this->app = static::resolveApp($this->app);
        if ($this->app !== null) {
            AppRegistry::rememberDashboard(auth()->user(), $this->app);
        }
    }

    /** The tab to show: the requested one if the tenant holds it, else the person's default. */
    public static function resolveApp(?string $requested): ?string
    {
        $available = AppRegistry::forTenant(Filament::getTenant());

        return is_string($requested) && in_array($requested, $available, true)
            ? $requested
            : AppRegistry::defaultFor(auth()->user(), $available);
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $apps   = AppRegistry::apps();
        $app    = static::resolveApp($this->app);

        $tabs = [];
        foreach (AppRegistry::forTenant($tenant) as $key) {
            $tabs[] = [
                'key'    => $key,
                'label'  => $apps[$key]['label'],
                'icon'   => $apps[$key]['icon'],
                'url'    => static::getUrl(['app' => $key]),
                'active' => $key === $app,
            ];
        }

        /** @var AppDashboard|null $dashboard */
        $dashboard = $app !== null ? app($apps[$app]['dashboard']) : null;

        return [
            'tabs'    => $tabs,
            'appView' => $dashboard?->view(),
            'appData' => $dashboard?->data($tenant) ?? [],
        ];
    }
}
