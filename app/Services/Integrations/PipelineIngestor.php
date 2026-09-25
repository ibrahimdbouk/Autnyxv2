<?php

namespace App\Services\Integrations;

use App\Models\Import;
use App\Services\Import\ColumnMappingService;
use App\Services\Import\FileReaderService;
use App\Services\Import\ImportProcessorService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns a set of rows (from an API pull or a public-API ingest call) into a CSV
 * and runs it through the STANDARD import pipeline, so every ingest path shares
 * the same validation, auto-mapping, dirty-key capture and async detection.
 *
 * See claude/api-integration-library.md and claude/public-api.md.
 */
class PipelineIngestor
{
    /** Safety cap on rows per ingest. */
    public const MAX_ROWS = 200000;

    public function __construct(
        private FileReaderService $reader,
        private ColumnMappingService $mapper,
        private ImportProcessorService $processor,
    ) {
    }

    /**
     * @param  iterable<int,array<string,mixed>>  $rows
     * @return Import|null  null when there were no rows
     */
    /** WP3.7: the last ingestRows() stopped at MAX_ROWS (the source had more). */
    public bool $lastTruncated = false;

    public function ingestRows(int $tenantId, string $dataType, iterable $rows, string $source = 'api', bool $queue = false): ?Import
    {
        $headers = [];
        $buffer  = [];
        $this->lastTruncated = false;

        foreach ($rows as $record) {
            $record = (array) $record;
            foreach (array_keys($record) as $h) {
                $headers[$h] = true;
            }
            $buffer[] = $record;
            if (count($buffer) >= self::MAX_ROWS) {
                $this->lastTruncated = true;
                break;
            }
        }

        if ($buffer === []) {
            return null;
        }

        $headerList = array_keys($headers);
        $filename   = $dataType . '_' . now()->format('Ymd_His') . '.csv';
        $localPath  = $source . '-imports/' . $tenantId . '/' . Str::uuid() . '_' . $filename;

        Storage::disk('local')->put($localPath, $this->toCsv($headerList, $buffer));

        $fullPath = Storage::disk('local')->path($localPath);
        $parsed   = $this->reader->read($fullPath);

        $import = Import::create([
            'tenant_id'         => $tenantId,
            'user_id'           => null,
            'original_filename' => $filename,
            'disk'              => 'local',
            'path'              => $localPath,
            'data_type'         => $dataType,
            'status'            => Import::STATUS_UPLOADED,
            'sample_rows'       => $parsed['rows'] ?? [],
            'total_rows'        => $parsed['total_rows'] ?? 0,
            // WP3.2: this CSV is written by us from JSON — dot decimals, UTF-8,
            // comma-delimited. Dates: ISO is always read; others follow the tenant.
            'decimal_separator' => '.',
            'delimiter'         => ',',
            'encoding'          => 'UTF-8',
        ]);

        foreach ($this->mapper->map($parsed['headers'] ?? [], $parsed['rows'] ?? [], $dataType, $tenantId) as $mapping) {
            $import->columnMaps()->create($mapping);
        }

        // WP3.3 (audit H24): an uncertain mapping waits for a person (the API
        // client sees status "mapping_review" when it polls).
        if (app(\App\Services\Import\MappingReviewGate::class)->holdIfUncertain($import)) {
            return $import->fresh();
        }

        // WP2.3 / WP3.7: always processed on the queue by the chunked pipeline
        // (the public API returns 202 + poll; scheduled pulls just enqueue).
        \App\Jobs\ProcessIngestedImportJob::dispatch($import->id)->afterCommit();

        return $import->fresh();
    }

    /**
     * @param  array<int,string>               $headers
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function toCsv(array $headers, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $headers, escape: '');

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $h) {
                $v = $row[$h] ?? null;
                $line[] = is_scalar($v) ? $v : ($v === null ? '' : json_encode($v));
            }
            fputcsv($fh, $line, escape: '');
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }
}
