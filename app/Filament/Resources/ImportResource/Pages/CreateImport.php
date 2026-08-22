<?php

namespace App\Filament\Resources\ImportResource\Pages;

use App\Filament\Resources\ImportResource;
use App\Models\Import;
use App\Services\Import\ColumnMappingService;
use App\Services\Import\FileReaderService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CreateImport extends Page
{
    protected static string $resource = ImportResource::class;

    protected string $view = 'filament.resources.import-resource.pages.create-import';

    protected static ?string $title = 'Upload Data File';

    public ?array $data = [];

    public bool $processing = false;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('data_type')
                    ->label('Data Type')
                    ->options(Import::dataTypeLabels())
                    ->required()
                    ->native(false)
                    ->helperText('What kind of data does this file contain?'),

                FileUpload::make('file')
                    ->label('File (CSV or Excel)')
                    ->required()
                    ->acceptedFileTypes([
                        'text/csv',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/plain',
                    ])
                    ->maxSize(20480) // 20 MB
                    ->disk('local')
                    ->directory('imports/pending')
                    ->helperText('Maximum 20 MB. CSV or .xlsx files supported.'),
            ])
            ->statePath('data');
    }

    // NB: method must NOT be named upload() — that collides with Livewire's
    // WithFileUploads::upload(), so wire:click/wire:submit never reach this code.
    public function startImport(): void
    {
        $this->processing = true;
        $diag = function ($m) {
            try {
                Storage::disk('local')->append('import_diag.log', now()->toDateTimeString() . ' ' . $m . "\n");
            } catch (\Throwable $e) {
            }
        };
        $diag('M1 upload() entered');

        try {
            $validated = $this->form->getState();
            $diag('M2 getState ok file=' . ($validated['file'] ?? 'NULL') . ' type=' . ($validated['data_type'] ?? 'NULL'));

            $filePath  = Storage::disk('local')->path($validated['file']);
            $diag('M3 path exists=' . (is_file($filePath) ? 'yes' : 'no'));
            $dataType  = $validated['data_type'];
            $tenantId  = \Filament\Facades\Filament::getTenant()->id;

            // 1. Read headers + sample rows
            $reader = app(FileReaderService::class);
            $result = $reader->read($filePath);
            $diag('M4 read ok headers=' . count($result['headers']) . ' rows=' . count($result['rows']) . ' total=' . $result['total_rows']);

            // 2. Create the Import record
            $import = Import::create([
                'tenant_id'         => $tenantId,
                'user_id'           => Auth::id(),
                'original_filename' => $validated['file'],
                'disk'              => 'local',
                'path'              => $validated['file'],
                'data_type'         => $dataType,
                'status'            => Import::STATUS_UPLOADED,
                'sample_rows'       => $result['rows'],
                'total_rows'        => $result['total_rows'],
            ]);
            $diag('M5 import created id=' . $import->id);

            // 3. Run AI column mapping
            $mapper   = app(ColumnMappingService::class);
            $mappings = $mapper->map($result['headers'], $result['rows'], $dataType);
            $diag('M6 map ok count=' . count($mappings));

            foreach ($mappings as $mapping) {
                $import->columnMaps()->create($mapping);
            }
            $diag('M7 columnMaps created');

            $import->update(['status' => Import::STATUS_MAPPING_REVIEW]);
            $diag('M8 redirecting to review');

            // 4. Redirect to mapping review
            $this->redirect(ReviewMapping::getUrl(['record' => $import]));
        } catch (\Throwable $e) {
            $this->processing = false;
            $diag('ERR ' . get_class($e) . ': ' . $e->getMessage());

            Notification::make()
                ->title('Upload failed')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }
}
