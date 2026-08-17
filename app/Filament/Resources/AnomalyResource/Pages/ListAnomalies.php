<?php

namespace App\Filament\Resources\AnomalyResource\Pages;

use App\Filament\Resources\AnomalyResource;
use App\Services\Anomaly\AnomalyDetectionService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListAnomalies extends ListRecords
{
    protected static string $resource = AnomalyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run_detection')
                ->label('Re-run Detection')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Re-run Anomaly Detection')
                ->modalDescription('This will clear all open anomalies and re-scan your data. Dismissed anomalies are kept. Continue?')
                ->action(function () {
                    $tenantId = Filament::getTenant()?->id;
                    if ($tenantId) {
                        app(AnomalyDetectionService::class)->runForTenant($tenantId);
                    }
                })
                ->visible(fn () => auth()->user()?->is_super_admin || auth()->user()?->is_tenant_admin),
        ];
    }
}
