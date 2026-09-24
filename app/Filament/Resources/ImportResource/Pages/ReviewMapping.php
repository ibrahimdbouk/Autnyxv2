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

    protected string $view = 'filament.resources.import-resource.pages.review-mapping';

    protected static ?string $title = 'Review Column Mapping';

    public Import $record;

    /** @var array<int, array> Live mapping state editable by the user */
    public array $mappings = [];

    public function mount(Import $record): void
    {
        $this->authorizeTenant($record);
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
        // Persist the user's choices back to the DB.
        // WP1.3 (audit H10): $mappings is client-editable Livewire state — only
        // ever touch THIS import's maps, and only with a real canonical field.
        $allowed = \App\Services\Import\CanonicalSchema::fieldNames((string) $this->record->data_type);
        foreach ($this->mappings as $mapping) {
            $target = $mapping['target_field'] ?? null;
            if ($target !== null && $target !== '' && ! in_array($target, $allowed, true)) {
                $target = null;
            }
            ImportColumnMap::where('id', (int) ($mapping['id'] ?? 0))
                ->where('import_id', $this->record->id)
                ->update([
                    'target_field' => $target ?: null,
                    'is_skipped'   => empty($target),
                    'is_confirmed' => true,
                ]);
        }

        // Kick off chunked, poll-driven processing. The heavy lifting happens
        // on the ProcessImport page, one memory-safe chunk per poll, so large
        // files no longer black-screen the request.
        try {
            app(ImportProcessorService::class)->startChunkedImport($this->record->fresh());

            $this->redirect(ProcessImport::getUrl(['record' => $this->record]));
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Could not start import')
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

    /** WP1.3 (audit H10): a record page only ever opens its own tenant's import. */
    private function authorizeTenant(Import $record): void
    {
        abort_unless((int) $record->tenant_id === (int) \Filament\Facades\Filament::getTenant()?->id, 404);
    }
}
