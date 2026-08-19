<?php

namespace App\Filament\Pages;

use App\Services\Quality\QualityMetricsService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

/**
 * Investigation Confidence & Quality Center — Feature 9
 *
 * Read-only. Every metric derives from real lifecycle events. Role-gated because
 * these numbers expose operational performance.
 */
class QualityCenter extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Quality Center';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'quality-center';

    protected string $view = 'filament.pages.quality-center';

    public function getTitle(): string
    {
        return 'Investigation Confidence & Quality';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canViewAuditLogs() ?? false;
    }

    public function getReport(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return [];
        }

        // Short cache — aggregations can be heavy; metrics don't need to be live.
        return Cache::remember(
            "quality_report_{$tenantId}",
            now()->addMinutes(10),
            fn () => app(QualityMetricsService::class)->report($tenantId)
        );
    }

    public function refreshReport(): void
    {
        $tenantId = Filament::getTenant()?->id;
        if ($tenantId) {
            Cache::forget("quality_report_{$tenantId}");
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->action(fn () => $this->refreshReport()),
        ];
    }
}
