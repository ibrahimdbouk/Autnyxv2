<?php

namespace App\Filament\Resources\ImportResource\Pages;

use App\Models\Anomaly;
use App\Models\Import;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Import\ColumnMappingService;
use App\Services\Import\FileReaderService;
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runDetection')
                ->label('Run Detection Now')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Run anomaly detection?')
                ->modalDescription('This scans all of your imported data now and refreshes anomalies. Detection also runs automatically after every import.')
                ->modalSubmitActionLabel('Run detection')
                ->action(function () {
                    $tenantId = Filament::getTenant()->id;

                    try {
                        app(AnomalyDetectionService::class)->runForTenant($tenantId);

                        $open = Anomaly::where('tenant_id', $tenantId)
                            ->where('status', Anomaly::STATUS_DETECTED)
                            ->count();

                        Notification::make()
                            ->title('Detection complete')
                            ->body("Scanned all data — {$open} open anomaly(ies) currently flagged.")
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Detection failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
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
                        $filePath = Storage::disk('local')->path($f);
                        $tenantId = Filament::getTenant()->id;

                        $reader = app(FileReaderService::class);
                        $result = $reader->read($filePath);

                        $import = Import::create([
                            'tenant_id'         => $tenantId,
                            'user_id'           => Auth::id(),
                            'original_filename' => $f,
                            'disk'              => 'local',
                            'path'              => $f,
                            'data_type'         => $dt,
                            'status'            => Import::STATUS_UPLOADED,
                            'sample_rows'       => $result['rows'],
                            'total_rows'        => $result['total_rows'],
                        ]);

                        $mapper = app(ColumnMappingService::class);
                        foreach ($mapper->map($result['headers'], $result['rows'], $dt) as $mapping) {
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
