<?php

namespace App\Filament\Resources\ImportResource\Pages;

use App\Filament\Resources\ImportResource;
use App\Models\Import;
use App\Services\Import\ColumnMappingService;
use App\Services\Import\FileReaderService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CreateImport extends Page
{
    protected static string $resource = ImportResource::class;

    protected static string $view = 'filament.resources.import-resource.pages.create-import';

    protected static ?string $title = 'Upload Data File';

    public ?array $data = [];

    public bool $processing = false;

    public function form(Form $form): Form
    {
        return $form
            ->schema([
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

    public function upload(): void
    {
        $validated = $this->form->getState();
        $this->processing = true;

        try {
            $filePath  = Storage::disk('local')->path($validated['file']);
            $dataType  = $validated['data_type'];
            $tenantId  = \Filament\Facades\Filament::getTenant()->id;

            // 1. Read headers + sample rows
            $reader = app(FileReaderService::class);
            $result = $reader->read($filePath);

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

            // 3. Run AI column mapping
            $mapper   = app(ColumnMappingService::class);
            $mappings = $mapper->map($result['headers'], $result['rows'], $dataType);

            foreach ($mappings as $mapping) {
                $import->columnMaps()->create($mapping);
            }

            $import->update(['status' => Import::STATUS_MAPPING_REVIEW]);

            // 4. Redirect to mapping review
            $this->redirect(ReviewMapping::getUrl(['record' => $import]));
        } catch (\Throwable $e) {
            $this->processing = false;

            Notification::make()
                ->title('Upload failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
