<?php

namespace App\Filament\Resources\ImportResource\Pages;

use App\Filament\Resources\ImportResource;
use App\Models\Import;
use App\Models\ImportColumnMap;
use App\Services\Import\CanonicalSchema;
use App\Services\Import\ImportProcessorService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Computed;

class ReviewMapping extends Page
{
    protected static string $resource = ImportResource::class;

    protected static string $view = 'filament.resources.import-resource.pages.review-mapping';

    protected static ?string $title = 'Review Column Mapping';

    public Import $record;

    /** @var array<int, array> Live mapping state editable by the user */
    public array $mappings = [];

    public function mount(Import $record): void
    {
        $this->record = $record;

        // Load current column maps into editable state
        $this->mappings = $record->columnMaps
            ->map(fn (ImportColumnMap $m) => [
                'id'            => $m->id,
                'source_header' => $m->source_header,
                'target_field'  => $m->target_field,
                'confidence'    => $m->confidence,
                'reasoning'     => $m->reasoning,
                'is_skipped'    => $m->is_skipped,
            ])
            ->values()
            ->toArray();
    }

    #[Computed]
    public function targetFieldOptions(): array
    {
        $schema = CanonicalSchema::forType($this->record->data_type);
        $options = ['' => '— Skip this column —'];
        foreach ($schema as $field => $meta) {
            $options[$field] = $meta['label'] . ($meta['required'] ? ' *' : '');
        }
        return $options;
    }

    #[Computed]
    public function sampleRows(): array
    {
        return $this->record->sample_rows ?? [];
    }

    public function confirmAndImport(): void
    {
        // Persist the user's choices back to the DB
        foreach ($this->mappings as $mapping) {
            ImportColumnMap::where('id', $mapping['id'])->update([
                'target_field' => $mapping['target_field'] ?: null,
                'is_skipped'   => empty($mapping['target_field']),
                'is_confirmed' => true,
            ]);
        }

        // Run the import processor synchronously (queue it in M8+)
        try {
            $processor = app(ImportProcessorService::class);
            $processor->process($this->record->fresh());

            $this->record->refresh();

            $msg = "Imported {$this->record->imported_rows} of {$this->record->total_rows} rows.";
            if ($this->record->failed_rows > 0) {
                $msg .= " {$this->record->failed_rows} rows need review.";
            }

            Notification::make()
                ->title($this->record->failed_rows > 0 ? 'Import completed with errors' : 'Import complete')
                ->body($msg)
                ->color($this->record->failed_rows > 0 ? 'warning' : 'success')
                ->send();

            $this->redirect(ViewImport::getUrl(['record' => $this->record]));
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Import failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Cancel')
                ->color('gray')
                ->url(ListImports::getUrl()),
        ];
    }
}
