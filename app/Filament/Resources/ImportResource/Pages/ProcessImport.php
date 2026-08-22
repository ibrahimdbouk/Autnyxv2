<?php

namespace App\Filament\Resources\ImportResource\Pages;

use App\Filament\Resources\ImportResource;
use App\Models\Import;
use App\Services\Import\ImportProcessorService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

class ProcessImport extends Page
{
    protected static string $resource = ImportResource::class;

    protected string $view = 'filament.resources.import-resource.pages.process-import';

    protected static ?string $title = 'Importing…';

    public Import $record;

    public bool $done = false;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label('Cancel import')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cancel this import?')
                ->modalDescription('Stops the import and removes any rows it has already added.')
                ->modalSubmitActionLabel('Yes, cancel')
                ->visible(fn () => ! $this->done && $this->record->isImporting())
                ->action(function () {
                    $removed = app(ImportProcessorService::class)->cancel($this->record);
                    $this->done = true;

                    Notification::make()
                        ->title('Import cancelled')
                        ->body("{$removed} partial row(s) removed.")
                        ->success()
                        ->send();

                    $this->redirect(ImportResource::getUrl('index'));
                }),
        ];
    }

    public function mount(Import $record): void
    {
        $this->record = $record;

        // Already finished (e.g. the page was revisited) — go straight to the summary.
        if (! $record->isImporting()) {
            $this->redirect(ViewImport::getUrl(['record' => $record]));

            return;
        }
    }

    /**
     * Driven by wire:poll — process one memory-safe chunk and advance the bar.
     */
    public function tick(): void
    {
        if ($this->done) {
            return;
        }

        $result = app(ImportProcessorService::class)->processChunk($this->record);
        $this->record->refresh();

        if ($result['done'] ?? false) {
            $this->done = true;
            $this->finishAndRedirect();
        }
    }

    protected function finishAndRedirect(): void
    {
        $this->record->refresh();

        if ($this->record->status === Import::STATUS_FAILED) {
            Notification::make()
                ->title('Import failed')
                ->body($this->record->error_message ?: 'The import could not be completed.')
                ->danger()
                ->send();
        } else {
            $msg = "Imported {$this->record->imported_rows} of {$this->record->total_rows} rows.";
            if ($this->record->failed_rows > 0) {
                $msg .= " {$this->record->failed_rows} rows need review.";
            }

            Notification::make()
                ->title($this->record->failed_rows > 0 ? 'Import completed with errors' : 'Import complete')
                ->body($msg)
                ->color($this->record->failed_rows > 0 ? 'warning' : 'success')
                ->send();
        }

        $this->redirect(ViewImport::getUrl(['record' => $this->record]));
    }
}
