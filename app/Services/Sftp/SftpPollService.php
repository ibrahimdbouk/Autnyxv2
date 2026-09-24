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

    /** WP3.7: partial / marker files are never imported themselves. */
    private const IGNORED_SUFFIXES = ['.done', '.tmp', '.part', '.filepart', '.partial', '.lock'];

    private function pollFeed(SftpConnection $connection, SftpFeed $feed, $disk): int
    {
        $count = 0;
        $dir   = trim($feed->remote_path ?: '.');

        $files = $disk->files($dir);
        $names = array_flip(array_map('strtolower', $files));

        foreach ($files as $path) {
            $filename = basename($path);
            $lower = strtolower($filename);

            if (str_starts_with($filename, '.') || array_filter(self::IGNORED_SUFFIXES, fn ($s) => str_ends_with($lower, $s))) {
                continue;
            }
            if (! $feed->matches($filename)) {
                continue;
            }

            // WP3.7 (audit H29): a file is its path + size + modification time —
            // a daily file overwritten at the same path is new data.
            $size  = (int) $disk->size($path);
            $mtime = (int) $disk->lastModified($path);

            $ledger = SftpIngestedFile::where('sftp_connection_id', $connection->id)
                ->where('remote_path', $path)->where('size_bytes', $size)->where('remote_mtime', $mtime)
                ->first();

            // A file ingested before WP3.7 has no mtime on record — same path and
            // size means it's the file we already have (don't re-import everything).
            if (! $ledger) {
                $legacy = SftpIngestedFile::where('sftp_connection_id', $connection->id)
                    ->where('remote_path', $path)->whereNull('remote_mtime')->where('size_bytes', $size)
                    ->first();
                if ($legacy) {
                    $legacy->forceFill(['remote_mtime' => $mtime])->save();
                    continue;
                }
            }

            if ($ledger) {
                $retryable = $ledger->status === SftpIngestedFile::STATUS_FAILED
                    && $ledger->attempts < SftpIngestedFile::MAX_ATTEMPTS
                    && ($ledger->next_attempt_at === null || $ledger->next_attempt_at->isPast());
                // Pending = seen unchanged on an earlier poll → complete; take it.
                if ($ledger->status !== SftpIngestedFile::STATUS_PENDING && ! $retryable) {
                    continue;
                }
            } else {
                $ledger = new SftpIngestedFile([
                    'tenant_id'          => $connection->tenant_id,
                    'sftp_connection_id' => $connection->id,
                    'sftp_feed_id'       => $feed->id,
                    'remote_path'        => $path,
                    'filename'           => $filename,
                    'size_bytes'         => $size,
                    'remote_mtime'       => $mtime,
                ]);

                // Stability: without a "<file>.done" marker, a new file is only
                // taken once a later poll sees it unchanged (not mid-upload).
                $marker = isset($names[strtolower($path . '.done')])
                    || isset($names[strtolower(preg_replace('/\.[^.\/]+$/', '', $path) . '.done')]);
                if (! $marker) {
                    $ledger->fill(['status' => SftpIngestedFile::STATUS_PENDING])->save();
                    continue;
                }
            }

            $this->ingestFile($connection, $feed, $disk, $ledger) && $count++;
        }

        return $count;
    }

    private function ingestFile(SftpConnection $connection, SftpFeed $feed, $disk, SftpIngestedFile $ledger): bool
    {
        $remotePath = $ledger->remote_path;
        $filename   = $ledger->filename;

        try {
            $contents = $disk->get($remotePath);
            if ($contents === null || $contents === '') {
                throw new \RuntimeException('Empty or unreadable remote file.');
            }

            // Store locally for the import pipeline.
            $localPath = 'sftp-imports/' . $connection->tenant_id . '/' . Str::uuid() . '_' . $filename;
            Storage::disk('local')->put($localPath, $contents);

            $import = $this->autoImport($connection->tenant_id, $feed->data_type, $localPath, $filename, $feed);

            $ledger->fill([
                'checksum'        => md5($contents),
                'import_id'       => $import->id,
                'status'          => SftpIngestedFile::STATUS_IMPORTED,
                'error'           => null,
                'next_attempt_at' => null,
                'processed_at'    => now(),
            ])->save();

            $this->afterImport($feed, $disk, $remotePath, $filename);

            return true;
        } catch (\Throwable $e) {
            // WP3.7: retried with backoff (10 min, 20, 40, 80 … capped at 6 h).
            $attempts = (int) $ledger->attempts + 1;
            $ledger->fill([
                'status'          => SftpIngestedFile::STATUS_FAILED,
                'error'           => Str::limit($e->getMessage(), 500),
                'attempts'        => $attempts,
                'next_attempt_at' => now()->addMinutes(min(360, 10 * 2 ** ($attempts - 1))),
                'processed_at'    => now(),
            ])->save();

            Log::error('[sftp] file ingest failed', ['path' => $remotePath, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Create + process an Import non-interactively (auto column mapping).
     */
    private function autoImport(int $tenantId, string $dataType, string $localPath, string $filename, ?SftpFeed $feed = null): Import
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
            // WP3.2: the feed's format (null → tenant default) + detected dialect.
            'date_format'       => $feed?->date_format,
            'decimal_separator' => $feed?->decimal_separator,
            'delimiter'         => $parsed['delimiter'] ?? null,
            'encoding'          => $parsed['encoding'] ?? null,
        ]);

        // Auto column mapping (learned memory first); WP3.3 holds it for review when unsure.
        $mappings = $this->mapper->map($parsed['headers'] ?? [], $parsed['rows'] ?? [], $dataType, $tenantId);
        foreach ($mappings as $mapping) {
            $import->columnMaps()->create($mapping);
        }

        // WP3.3 (audit H24): an uncertain mapping waits for a person.
        if (app(\App\Services\Import\MappingReviewGate::class)->holdIfUncertain($import)) {
            return $import->fresh();
        }

        // WP3.7: processed on the queue by the chunked pipeline (screen → write →
        // aggregate → incremental detection), never inline in the poller.
        \App\Jobs\ProcessIngestedImportJob::dispatch($import->id)->afterCommit();

        return $import->fresh();
    }

    private function afterImport(SftpFeed $feed, $disk, string $remotePath, string $filename): void
    {
        try {
            if ($feed->archive_path) {
                // WP3.7: a unique name — archiving today's "sales.csv" must not
                // overwrite (or fail on) yesterday's archived copy.
                $ext  = pathinfo($filename, PATHINFO_EXTENSION);
                $base = pathinfo($filename, PATHINFO_FILENAME);
                $dest = rtrim($feed->archive_path, '/') . '/' . $base . '_' . now()->format('Ymd_His') . '_' . Str::lower(Str::random(4)) . ($ext !== '' ? '.' . $ext : '');
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
