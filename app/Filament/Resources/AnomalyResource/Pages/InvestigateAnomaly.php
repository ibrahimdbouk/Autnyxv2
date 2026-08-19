<?php

namespace App\Filament\Resources\AnomalyResource\Pages;

use App\Filament\Resources\AnomalyResource;
use App\Filament\Resources\InvestigationResource;
use App\Models\Anomaly;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;

class InvestigateAnomaly extends Page
{
    protected static string $resource = AnomalyResource::class;

    protected string $view = 'filament.resources.anomaly-resource.pages.investigate-anomaly';

    public Anomaly $record;

    public function mount(int|string $record): void
    {
        $this->record = Anomaly::with(['investigation.assignedTeam', 'investigation.assignedUser'])
            ->findOrFail($record);

        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(
            $this->record->tenant_id === \Filament\Facades\Filament::getTenant()?->id,
            403
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            // Primary action: go to the canonical Investigation page where AI narrative,
            // evidence, actions, escalations and outcomes all live (M16–M21 architecture).
            Action::make('view_investigation')
                ->label('View Investigation')
                ->icon('heroicon-o-magnifying-glass')
                ->color('primary')
                ->visible(fn () => (bool) $this->record->investigation_id)
                ->url(fn () => $this->record->investigation_id
                    ? InvestigationResource::getUrl('investigate', ['record' => $this->record->investigation_id])
                    : '#'
                ),

            Action::make('download_pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn () => route('anomaly.report.pdf', $this->record->id))
                ->openUrlInNewTab(),

            Action::make('back')
                ->label('Back to Anomalies')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(AnomalyResource::getUrl('index')),
        ];
    }

    public function getTitle(): string
    {
        return 'Anomaly #' . $this->record->id;
    }
}
