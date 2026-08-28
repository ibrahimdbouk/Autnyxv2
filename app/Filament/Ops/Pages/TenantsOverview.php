<?php

namespace App\Filament\Ops\Pages;

use App\Services\Ops\GrowthMetricsService;
use App\Services\Ops\PlatformHealthService;
use App\Services\Ops\TenantUsageService;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Cache;

/**
 * 2a — the control plane home: every tenant with usage at a glance, the
 * platform-wide headline tiles, a live health strip, and 30-day growth
 * sparklines. Read-only; the heavy aggregates are cached.
 */
class TenantsOverview extends BaseDashboard
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Tenants';

    protected string $view = 'filament.ops.pages.tenants-overview';

    public function getTitle(): string
    {
        return 'Tenants';
    }

    /** @return array<string,mixed> */
    public function getOverview(): array
    {
        return Cache::remember('ops_overview', now()->addMinutes(5),
            fn () => app(TenantUsageService::class)->overview());
    }

    /** @return array<int,array<string,mixed>> */
    public function getTenants(): array
    {
        return Cache::remember('ops_tenants', now()->addMinutes(5),
            fn () => app(TenantUsageService::class)->perTenant());
    }

    /** Platform-health verdict strip. */
    public function getHealth(): array
    {
        return Cache::remember('ops_home_health', now()->addMinutes(5),
            fn () => app(PlatformHealthService::class)->summary());
    }

    /**
     * 30-day growth series for the home sparklines.
     *
     * @return array<string,mixed>
     */
    public function getGrowth(): array
    {
        return Cache::remember('ops_home_growth', now()->addMinutes(5), function () {
            $g = app(GrowthMetricsService::class);

            return [
                'totals'    => $g->totals(),
                'tenants'   => $g->series($g->newTenantsDaily(30), 30),
                'users'     => $g->series($g->newUsersDaily(30), 30),
                'anomalies' => $g->series($g->anomaliesDetectedDaily(30), 30),
                'resolved'  => $g->series($g->resolvedDaily(30), 30),
            ];
        });
    }

    public function refresh(): void
    {
        Cache::forget('ops_overview');
        Cache::forget('ops_tenants');
        Cache::forget('ops_home_health');
        Cache::forget('ops_home_growth');
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->action(fn () => $this->refresh()),
            \Filament\Actions\Action::make('new_tenant')
                ->label('New tenant')
                ->icon('heroicon-o-plus')
                ->url(fn () => \App\Filament\Ops\Resources\TenantResource::getUrl('create')),
        ];
    }
}
