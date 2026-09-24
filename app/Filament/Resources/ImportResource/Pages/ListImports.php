<?php

namespace App\Filament\Resources\ImportResource\Pages;

use App\Models\Import;
use App\Models\Investigation;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Anomaly\InvestigationCorrelationService;
use App\Services\Import\ColumnMappingService;
use App\Services\Import\FileReaderService;
use App\Services\Import\ImportProcessorService;
use App\Services\Import\ValueParser;
use App\Services\Storage\TenantStorage;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ListImports extends ListRecords
{
    protected static string $resource = \App\Filament\Resources\ImportResource::class;

    public function mount(): void
    {
        parent::mount();

        // Self-heal imports whose browser tab was closed mid-run. Batch imports
        // finish in well under a minute, and an actively-polling import keeps its
        // timestamp fresh, so only a genuinely stalled one is flagged — flipping
        // it to "failed" surfaces the Cancel / Undo action instead of leaving it
        // stuck in "importing" forever.
        // WP1.3: scoped to this tenant, and 10 minutes (was 2, across ALL
        // tenants) so a long SFTP/API run elsewhere is never failed mid-flight.
        if ($tenantId = \Filament\Facades\Filament::getTenant()?->id) {
            ImportProcessorService::recoverStuckImports(10, $tenantId);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runDetection')
                ->label('Run Detection Now')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Run anomaly detection?')
                ->modalDescription('Scans all of your imported data, groups the findings into prioritised investigations, and refreshes your dashboards. On large datasets this can take up to a minute.')
                ->modalSubmitActionLabel('Run detection')
                ->action(function () {
                    $tenantId = Filament::getTenant()->id;

                    // Detection runs off the web request (it can take minutes on large
                    // data). Dispatched to the queue; a worker picks it up.
                    \App\Jobs\RunTenantDetectionJob::dispatch($tenantId);

                    Notification::make()
                        ->title('Detection started')
                        ->body('Your data is being scanned in the background — investigations will refresh automatically in a few minutes.')
                        ->success()
                        ->send();
                }),

            // WP3.2 (D4): the tenant's default way of reading dates and numbers.
            Action::make('importSettings')
                ->label('Import settings')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->visible(fn () => (bool) auth()->user()?->canManageImports() && (bool) auth()->user()?->isTenantAdmin())
                ->modalHeading('How your files are read')
                ->modalDescription('Defaults for every upload and SFTP feed. Each upload or feed can override them. A date that does not fit the format is rejected with a reason — it is never guessed.')
                ->fillForm(fn () => [
                    'import_date_format'       => ValueParser::forTenant(Filament::getTenant())->dateFormat,
                    'import_decimal_separator' => ValueParser::forTenant(Filament::getTenant())->decimal,
                ])
                ->form([
                    Select::make('import_date_format')
                        ->label('Date format')
                        ->options(ValueParser::DATE_FORMATS)
                        ->required(),
                    Select::make('import_decimal_separator')
                        ->label('Decimal separator (CSV / text files)')
                        ->options(ValueParser::DECIMALS)
                        ->required()
                        ->helperText('Excel files always use the numbers stored in the cells.'),
                ])
                ->action(function (array $data) {
                    abort_unless(auth()->user()?->isTenantAdmin(), 403);
                    $tenant = Filament::getTenant();
                    $tenant->settings = array_merge($tenant->settings ?? [], [
                        'import_date_format'       => array_key_exists($data['import_date_format'] ?? '', ValueParser::DATE_FORMATS) ? $data['import_date_format'] : ValueParser::DEFAULT_DATE_FORMAT,
                        'import_decimal_separator' => array_key_exists($data['import_decimal_separator'] ?? '', ValueParser::DECIMALS) ? $data['import_decimal_separator'] : ValueParser::DEFAULT_DECIMAL,
                    ]);
                    $tenant->save();
                    Notification::make()->title('Import settings saved')->success()->send();
                }),

            Action::make('upload')
                ->label('Upload File')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Upload a Data File')
                ->modalDescription('Select the data type and upload your CSV or Excel file. Our AI will map the columns for you.')
                ->modalSubmitActionLabel('Upload & Map Columns')
                ->form([
                    Select::make('data_type')
                        ->label('Data Type')
                        ->options(Import::dataTypeLabels())
                        ->required()
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

                    // WP3.2 (D4): per-file override of the tenant default.
                    Select::make('date_format')
                        ->label('Date format in this file')
                        ->options(ValueParser::DATE_FORMATS)
                        ->default(fn () => ValueParser::forTenant(Filament::getTenant())->dateFormat)
                        ->required(),
                    Select::make('decimal_separator')
                        ->label('Decimal separator (CSV / text files)')
                        ->options(ValueParser::DECIMALS)
                        ->default(fn () => ValueParser::forTenant(Filament::getTenant())->decimal)
                        ->required(),
                ])
                ->action(function (array $data, $livewire) {
                    $dt = $data['data_type'] ?? null;
                    $f  = $data['file'] ?? null;

                    if (empty($dt) || empty($f)) {
                        Notification::make()
                            ->title('Missing information')
                            ->body('Please choose a data type and select a file.')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        $tenantId = Filament::getTenant()->id;

                        // 3a — relocate the staged upload into private,
                        // tenant-isolated storage (durable + encrypted on S3),
                        // then read the sample from wherever it now lives.
                        $storage = app(TenantStorage::class);
                        $ext = pathinfo($f, PATHINFO_EXTENSION) ?: 'csv';
                        $securePath = $storage->putStream(
                            $tenantId,
                            TenantStorage::CATEGORY_IMPORTS,
                            Storage::disk('local')->readStream($f),
                            $ext,
                        );
                        Storage::disk('local')->delete($f); // drop the staging copy

                        $filePath = $storage->localPath($storage->diskName(), $securePath);

                        $reader = app(FileReaderService::class);
                        $result = $reader->read($filePath);

                        $import = Import::create([
                            'tenant_id'         => $tenantId,
                            'user_id'           => Auth::id(),
                            'original_filename' => basename($f),
                            'disk'              => $storage->diskName(),
                            'path'              => $securePath,
                            'data_type'         => $dt,
                            'status'            => Import::STATUS_UPLOADED,
                            'sample_rows'       => $result['rows'],
                            'total_rows'        => $result['total_rows'],
                            // WP3.2: how this file is read, decided once.
                            'date_format'       => array_key_exists($data['date_format'] ?? '', ValueParser::DATE_FORMATS) ? $data['date_format'] : null,
                            'decimal_separator' => array_key_exists($data['decimal_separator'] ?? '', ValueParser::DECIMALS) ? $data['decimal_separator'] : null,
                            'delimiter'         => $result['delimiter'] ?? null,
                            'encoding'          => $result['encoding'] ?? null,
                        ]);

                        $mapper = app(ColumnMappingService::class);
                        foreach ($mapper->map($result['headers'], $result['rows'], $dt, $tenantId) as $mapping) {
                            $import->columnMaps()->create($mapping);
                        }

                        $import->update(['status' => Import::STATUS_MAPPING_REVIEW]);

                        $livewire->redirect(ReviewMapping::getUrl(['record' => $import]));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Upload failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }
}
