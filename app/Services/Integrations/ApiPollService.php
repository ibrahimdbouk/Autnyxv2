<?php

namespace App\Services\Integrations;

use App\Models\ApiConnection;
use App\Models\ApiFeed;
use App\Models\Import;
use App\Services\Import\ColumnMappingService;
use App\Services\Import\FileReaderService;
use App\Services\Import\ImportProcessorService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pulls data from a source system's API and runs it through the STANDARD import
 * pipeline — the API sibling of SftpPollService. Each feed's records are fetched
 * via the connector, written to a CSV, and handed to ImportProcessorService::process(),
 * so API ingest inherits column auto-mapping, validation, dirty-key capture and the
 * async detection trigger with no new downstream code.
 *
 * See claude/api-integration-library.md.
 */
class ApiPollService
{
    /** Safety cap on rows buffered per feed pull (Phase 1). */
    private const MAX_ROWS = 200000;

    public function __construct(
        private ConnectorRegistry $registry,
        private FileReaderService $reader,
        private ColumnMappingService $mapper,
        private ImportProcessorService $processor,
    ) {
    }

    /** Poll every active connection for a tenant. Returns feeds ingested. */
    public function pollTenant(int $tenantId): int
    {
        $count = 0;
        foreach (ApiConnection::where('tenant_id', $tenantId)->where('is_active', true)->get() as $connection) {
            $count += $this->pollConnection($connection);
        }

        return $count;
    }

    /** Poll one connection across all its enabled feeds. */
    public function pollConnection(ApiConnection $connection): int
    {
        $ingested = 0;
        $hadError = false;
        $connector = $this->registry->for($connection);

        foreach ($connection->feeds()->where('enabled', true)->get() as $feed) {
            try {
                if ($this->ingestFeed($connection, $feed, $connector)) {
                    $ingested++;
                }
            } catch (\Throwable $e) {
                $hadError = true;
                Log::error('[api] feed poll failed', ['feed' => $feed->id, 'error' => $e->getMessage()]);
                $connection->last_error = Str::limit($e->getMessage(), 500);
            }
        }

        $connection->forceFill([
            'status'         => $hadError ? ApiConnection::STATUS_ERROR : ApiConnection::STATUS_OK,
            'last_polled_at' => now(),
            'last_error'     => $hadError ? ($connection->last_error ?: 'One or more feeds failed — see logs.') : null,
        ])->save();

        return $ingested;
    }

    /** Fetch a feed's records, write a CSV, and run the standard import. */
    private function ingestFeed(ApiConnection $connection, ApiFeed $feed, $connector): bool
    {
        $headers = [];
        $rows    = [];

        foreach ($connector->fetch($connection, $feed) as $record) {
            foreach (array_keys($record) as $h) {
                $headers[$h] = true;
            }
            $rows[] = $record;
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        if ($rows === []) {
            return false; // nothing new/available — not an error
        }

        $headerList = array_keys($headers);
        $filename   = $feed->data_type . '_' . now()->format('Ymd_His') . '.csv';
        $localPath  = 'api-imports/' . $connection->tenant_id . '/' . Str::uuid() . '_' . $filename;

        Storage::disk('local')->put($localPath, $this->toCsv($headerList, $rows));

        $this->autoImport($connection->tenant_id, $feed->data_type, $localPath, $filename);

        return true;
    }

    /** Create + process an Import non-interactively (auto column mapping). */
    private function autoImport(int $tenantId, string $dataType, string $localPath, string $filename): Import
    {
        $fullPath = Storage::disk('local')->path($localPath);
        $parsed   = $this->reader->read($fullPath);

        $import = Import::create([
            'tenant_id'         => $tenantId,
            'user_id'           => null, // system / automated
            'original_filename' => $filename,
            'disk'              => 'local',
            'path'              => $localPath,
            'data_type'         => $dataType,
            'status'            => Import::STATUS_UPLOADED,
            'sample_rows'       => $parsed['rows'] ?? [],
            'total_rows'        => $parsed['total_rows'] ?? 0,
        ]);

        foreach ($this->mapper->map($parsed['headers'] ?? [], $parsed['rows'] ?? [], $dataType) as $mapping) {
            $import->columnMaps()->create($mapping);
        }

        $this->processor->process($import);

        return $import->fresh();
    }

    /**
     * @param  array<int,string>               $headers
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function toCsv(array $headers, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $headers);

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $h) {
                $v = $row[$h] ?? null;
                $line[] = is_scalar($v) ? $v : ($v === null ? '' : json_encode($v));
            }
            fputcsv($fh, $line);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }
}
