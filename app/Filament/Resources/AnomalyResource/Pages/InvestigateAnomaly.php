<?php

namespace App\Filament\Resources\AnomalyResource\Pages;

use App\Filament\Resources\AnomalyResource;
use App\Models\Anomaly;
use App\Services\Anomaly\AnomalyInvestigationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

class InvestigateAnomaly extends Page
{
    protected static string $resource = AnomalyResource::class;

    protected string $view = 'filament.resources.anomaly-resource.pages.investigate-anomaly';

    public Anomaly $record;

    public function mount(int|string $record): void
    {
        $this->record = Anomaly::findOrFail($record);

        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        // Scope to tenant
        abort_unless(
            $this->record->tenant_id === \Filament\Facades\Filament::getTenant()?->id,
            403
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run_investigation')
                ->label($this->record->isInvestigated() ? 'Re-run Investigation' : 'Run Investigation')
                ->icon('heroicon-o-cpu-chip')
                ->color('primary')
                ->requiresConfirmation(fn () => $this->record->isInvestigated())
                ->modalHeading('Re-run Investigation?')
                ->modalDescription('This will overwrite the existing AI analysis.')
                ->action(function () {
                    $service = app(AnomalyInvestigationService::class);
                    $service->investigate($this->record);
                    $this->record = $this->record->fresh();

                    Notification::make()
                        ->title('Investigation complete')
                        ->success()
                        ->send();
                }),

            Action::make('mark_action_taken')
                ->label('Mark Action Taken')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->investigation_status === Anomaly::STATUS_CAUSE_ESTABLISHED)
                ->form([
                    \Filament\Forms\Components\Textarea::make('action_notes')
                        ->label('What action did you take?')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data) {
                    $this->record->update([
                        'investigation_status' => Anomaly::STATUS_ACTION_TAKEN,
                        'action_taken_at'      => now(),
                        'action_notes'         => $data['action_notes'],
                    ]);
                    $this->record = $this->record->fresh();

                    Notification::make()
                        ->title('Action recorded')
                        ->success()
                        ->send();
                }),

            Action::make('resolve')
                ->label('Resolve')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn () => $this->record->investigation_status === Anomaly::STATUS_ACTION_TAKEN)
                ->form([
                    \Filament\Forms\Components\Textarea::make('resolution_notes')
                        ->label('Resolution notes')
                        ->rows(3),
                    \Filament\Forms\Components\Toggle::make('resolved')
                        ->label('Issue resolved?')
                        ->default(true),
                ])
                ->action(function (array $data) {
                    $this->record->update([
                        'investigation_status' => $data['resolved']
                            ? Anomaly::STATUS_RESOLVED
                            : Anomaly::STATUS_UNRESOLVED,
                        'resolved_at'       => now(),
                        'resolution_notes'  => $data['resolution_notes'],
                    ]);
                    $this->record = $this->record->fresh();

                    Notification::make()
                        ->title($data['resolved'] ? 'Anomaly resolved' : 'Marked as unresolved')
                        ->success()
                        ->send();
                }),

            Action::make('back')
                ->label('Back to Anomalies')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(AnomalyResource::getUrl('index')),
        ];
    }

    public function getTitle(): string
    {
        return 'Investigate Anomaly #' . $this->record->id;
    }
}
