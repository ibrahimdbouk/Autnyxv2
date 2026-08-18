<?php

namespace App\Filament\Resources\ImportResource\Pages;

use App\Filament\Resources\ImportResource;
use App\Models\Import;
use App\Models\ImportRow;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Import\ImportProcessorService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;

class ViewImport extends Page
{
    use WithPagination;

    protected static string $resource = ImportResource::class;

    protected string $view = 'filament.resources.import-resource.pages.view-import';

    protected static ?string $title = 'Import Details';

    public Import $record;

    public string $statusFilter = 'pending_review';

    public function mount(Import $record): void
    {
        $this->record = $record;
    }

    #[Computed]
    public function failedRows()
    {
        return ImportRow::where('import_id', $this->record->id)
            ->where('status', $this->statusFilter)
            ->orderBy('row_number')
            ->paginate(25);
    }

    public function approveRow(int $rowId): void
    {
        ImportRow::where('id', $rowId)->update(['status' => ImportRow::STATUS_APPROVED]);
        $this->resetPage();
    }

    public function rejectRow(int $rowId): void
    {
        ImportRow::where('id', $rowId)->update(['status' => ImportRow::STATUS_REJECTED]);
        $this->resetPage();
    }

    public function retryAllFailed(): void
    {
        $rows = ImportRow::where('import_id', $this->record->id)
            ->whereIn('status', [ImportRow::STATUS_PENDING, 'pending_review'])
            ->get();

        if ($rows->isEmpty()) {
            Notification::make()->title('No rows to retry')->warning()->send();
            return;
        }

        $result = app(ImportProcessorService::class)->retryRows($this->record, $rows);
        $this->record->refresh();
        $this->resetPage();

        Notification::make()
            ->title("Retry complete: {$result['retried']} succeeded, {$result['still_failed']} still failed")
            ->color($result['still_failed'] > 0 ? 'warning' : 'success')
            ->send();
    }

    public function runDetectionNow(): void
    {
        $tenantId = Filament::getTenant()?->id;
        if (!$tenantId) return;

        try {
            app(AnomalyDetectionService::class)->runForTenant($tenantId);
            Notification::make()->title('Anomaly detection completed')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Detection failed: ' . $e->getMessage())->danger()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('retry_failed')
                ->label('Retry Failed Rows')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => ImportRow::where('import_id', $this->record->id)->exists())
                ->requiresConfirmation()
                ->modalHeading('Retry all failed rows?')
                ->modalDescription('This will re-attempt every failed row using the same column mapping. Rows that succeed will be removed from this list.')
                ->action(fn () => $this->retryAllFailed()),

            Action::make('run_detection')
                ->label('Run Detection Now')
                ->icon('heroicon-o-cpu-chip')
                ->color('primary')
                ->visible(fn () => $this->record->isCompleted())
                ->requiresConfirmation()
                ->modalHeading('Run anomaly detection?')
                ->modalDescription('This scans your imported data immediately, without waiting for the nightly run.')
                ->action(fn () => $this->runDetectionNow()),

            Action::make('back')
                ->label('All Imports')
                ->color('gray')
                ->url(ListImports::getUrl()),
        ];
    }
}
