<?php

namespace App\Services\Sftp;

use App\Models\Import;
use App\Models\SftpConnection;
use App\Models\SftpFeed;
use App\Models\SftpIngestedFile;
use App\Services\Import\ColumnMappingService;
use App\Services\Import\FileReaderService;
use App\Services\Import\ImportProcessorService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SftpPollService — M14. For each active connection + enabled feed, discovers
 * new remote files, downloads them, and runs them through the existing import
 * pipeline non-interactively (auto column mapping → process → anomaly detection).
 * Idempotent via the sftp_ingested_files ledger; optionally archives/deletes the
 * remote file afterwards.
 */
class SftpPollService
{
    public function __construct(
        private SftpService $sftp,
        private FileReaderService $reader,
        private ColumnMappingService $mapper,
        private ImportProcessorService $processor,
    ) {
    }

    /**
     * Poll every active connection for a tenant. Returns files imported.
     */
    public function pollTenant(int $tenantId): int
    {
        $imported = 0;
        $connections = SftpConnection::where('tenant_id', $tenantId)->where('is_active', true)->get();
        foreach ($connections as $connection) {
            $imported += $this->pollConnection($connection);
        }
        return $imported;
    }

    /**
     * Poll a single connection across all its enabled feeds.
     */
    public function pollConnection(SftpConnection $connection): int
    {
        $importedCount = 0;

        try {
            $disk = $this->sftp->disk($connection);
        } catch (\Throwable $e) {
            $connection->update([
                'status'         => SftpConnection::STATUS_ERROR,
                'last_polled_at' => now(),
                'last_error'     => $this->sftp->cleanError($e->getMessage()),
            ]);
            return 0;
        }

        $hadError = false;

        foreach ($connection->feeds()->where('enabled', true)->get() as $feed) {
            try {
                $importedCount += $this->pollFeed($connection, $feed, $disk);
            } catch (\Throwable $e) {
                $hadError = true;
                Log::error('[sftp] feed poll failed', ['feed' => $feed->id, 'error' => $e->getMessage()]);
            }
        }

        $connection->update([
            'status'         => $hadError ? SftpConnection::STATUS_ERROR : SftpConnection::STATUS_OK,
            'last_polled_at' => now(),
            'last_error'     => $hadError ? ($connection->last_error ?: 'One or more feeds failed — see logs.') : null,
        ]);

        return $importedCount;
    }

    private function pollFeed(SftpConnection $connection, SftpFeed $feed, $disk): int
    {
        $count = 0;
        $dir   = trim($feed->remote_path ?: '.');

        $files = $disk->files($dir);

        foreach ($files as $path) {
            $filename = basename($path);

            if (! $feed->matches($filename)) {
                continue;
            }

            // Skip already-ingested files (idempotency).
            $already = SftpIngestedFile::where('sftp_connection_id', $connection->id)
                ->where('remote_path', $path)
                ->exists();
            if ($already) {
                continue;
            }

            $this->ingestFile($connection, $feed, $disk, $path, $filename) && $count++;
        }

        return $count;
    }

    private function ingestFile(SftpConnection $connection, SftpFeed $feed, $disk, string $remotePath, string $filename): bool
    {
        $ledger = new SftpIngestedFile([
            'tenant_id'          => $connection->tenant_id,
            'sftp_connection_id' => $connection->id,
            'sftp_feed_id'       => $feed->id,
            'remote_path'        => $remotePath,
            'filename'           => $filename,
        ]);

        try {
            $contents = $disk->get($remotePath);
            if ($contents === null || $contents === '') {
                throw new \RuntimeException('Empty or unreadable remote file.');
            }

            // Store locally for the import pipeline.
            $localPath = 'sftp-imports/' . $connection->tenant_id . '/' . Str::uuid() . '_' . $filename;
            Storage::disk('local')->put($localPath, $contents);

            $import = $this->autoImport($connection->tenant_id, $feed->data_type, $localPath, $filename);

            $ledger->fill([
                'size_bytes'   => strlen($contents),
                'checksum'     => md5($contents),
                'import_id'    => $import->id,
                'status'       => SftpIngestedFile::STATUS_IMPORTED,
                'processed_at' => now(),
            ])->save();

            $this->afterImport($feed, $disk, $remotePath, $filename);

            return true;
        } catch (\Throwable $e) {
            $ledger->fill([
                'status'       => SftpIngestedFile::STATUS_FAILED,
                'error'        => Str::limit($e->getMessage(), 500),
                'processed_at' => now(),
            ])->save();

            Log::error('[sftp] file ingest failed', ['path' => $remotePath, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Create + process an Import non-interactively (auto column mapping).
     */
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

        // Auto column mapping — accept the AI/fuzzy matches without human review.
        $mappings = $this->mapper->map($parsed['headers'] ?? [], $parsed['rows'] ?? [], $dataType);
        foreach ($mappings as $mapping) {
            $import->columnMaps()->create($mapping);
        }

        // Run the standard processor (writes rows, records IngestionRun, detects anomalies).
        $this->processor->process($import);

        return $import->fresh();
    }

    private function afterImport(SftpFeed $feed, $disk, string $remotePath, string $filename): void
    {
        try {
            if ($feed->archive_path) {
                $dest = rtrim($feed->archive_path, '/') . '/' . $filename;
                $disk->move($remotePath, $dest);
            } elseif ($feed->delete_after) {
                $disk->delete($remotePath);
            }
        } catch (\Throwable $e) {
            // Non-fatal — the file is already recorded as ingested so it won't reimport.
            Log::warning('[sftp] post-import archive/delete failed', ['path' => $remotePath, 'error' => $e->getMessage()]);
        }
    }
}
