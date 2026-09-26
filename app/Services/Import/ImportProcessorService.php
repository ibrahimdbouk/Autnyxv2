<?php

namespace App\Services\Import;

use App\Models\IngestionRun;
use App\Models\Import;
use App\Models\ImportColumnMap;
use App\Models\ImportQuality;
use App\Models\ImportRow;
use App\Models\QuarantinedRow;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesReturn;
use App\Models\SalesTransaction;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DataQuality\DataQualityFirewall;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Applies a confirmed column mapping to the uploaded file and writes rows to the DB.
 * Failed rows are stored in import_rows for review.
 */
class ImportProcessorService
{
    /** Rows processed per poll tick. Batch inserts keep this fast and well under any request timeout. */
    public const CHUNK_SIZE = 5000;

    /** Rows per multi-row INSERT statement (bounded to stay under Postgres' parameter limit). */
    private const INSERT_BATCH = 2000;

    /** @var array<string,int>|null  name => store_id, primed per tenant */
    private ?array $storeCache = null;

    /** @var array<string,int>|null  name => supplier_id, primed per tenant */
    private ?array $supplierCache = null;

    /** @var array<string,int>|null  sku => product_id, primed per tenant */
    private ?array $productCache = null;

    /** Tenant the caches above were primed for. */
    private ?int $cachedTenantId = null;

    /** WP3.2: date / number rules of the import being processed. */
    private ?ValueParser $parser = null;
    private ?ValueParser $canonicalParser = null;

    /** WP3.1: receipt → last line number seen in this import (file order). */
    private array $lineCounters = [];

    /** WP3.1: receipts whose counters changed in the current chunk. */
    private array $touchedReceipts = [];

    /** WP3.1: set by writeSalesTransaction() when the line was already loaded. */
    private bool $lastWriteWasDuplicate = false;

    /** Data Quality Firewall — resolved lazily, null when disabled via config. */
    private ?DataQualityFirewall $firewall = null;
    private bool $firewallResolved = false;

    /** The firewall for this run, or null when the feature is off. Cleanses + gates every row. */
    private function firewall(): ?DataQualityFirewall
    {
        if (! $this->firewallResolved) {
            $this->firewallResolved = true;
            $this->firewall = DataQualityFirewall::isEnabled() ? app(DataQualityFirewall::class) : null;
        }

        return $this->firewall;
    }

    /**
     * Load every store / supplier / product for the tenant into in-memory maps
     * once, so per-row resolution is an O(1) array hit instead of a DB round
     * trip. This is what turns a 25k-row import from ~75k queries into ~3.
     */
    private function primeCaches(int $tenantId): void
    {
        if ($this->cachedTenantId === $tenantId && $this->storeCache !== null) {
            return;
        }

        // WP3.4: case/space-insensitive keys; codes resolve too (names win on a clash).
        $this->storeCache = [];
        $stores = Store::where('tenant_id', $tenantId)->orderBy('id')->get(['id', 'name', 'code']);
        foreach ($stores as $st) {
            $this->storeCache[self::norm($st->name)] ??= (int) $st->id;
        }
        foreach ($stores as $st) {
            if ($st->code) {
                $this->storeCache[self::norm($st->code)] ??= (int) $st->id;
            }
        }

        $this->supplierCache = [];
        foreach (Supplier::where('tenant_id', $tenantId)->orderBy('id')->get(['id', 'name']) as $sup) {
            $this->supplierCache[self::norm($sup->name)] ??= (int) $sup->id;
        }

        $this->productCache = Product::where('tenant_id', $tenantId)
            ->pluck('id', 'sku')
            ->mapWithKeys(fn ($id, $sku) => [trim((string) $sku) => (int) $id])
            ->all();

        $this->cachedTenantId = $tenantId;
    }

    /**
     * Resolve a product id from SKU using the primed cache (null if unknown).
     */
    private function resolveProductId(int $tenantId, ?string $sku): ?int
    {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return null;
        }

        if ($this->productCache !== null && $this->cachedTenantId === $tenantId) {
            return $this->productCache[$sku] ?? null;
        }

        // Fallback (e.g. single-row retry path where caches aren't primed).
        return Product::where('tenant_id', $tenantId)->where('sku', $sku)->value('id');
    }

    /**
     * Retry a specific set of failed ImportRow records.
     * Uses the already-mapped data stored on the row so the file doesn't need to be re-read.
     * Successfully retried rows are deleted; persistent failures update the error message.
     */
    public function retryRows(Import $import, \Illuminate\Support\Collection $rows): array
    {
        $retried = 0;
        $stillFailed = 0;
        $duplicates = 0;
        $quarantined = 0;
        $this->parser = ValueParser::forImport($import);
        $this->primeCaches((int) $import->tenant_id);

        // WP3.5: a retried row goes through the same gate as a first attempt —
        // normalise, cleanse + validate (quarantine), write — and afterwards the
        // same post-import hooks run.
        $fw = $this->firewall();
        $fw?->begin($import);

        foreach ($rows as $importRow) {
            $mappedData = $this->parser()->normalizeRow($import->data_type, $importRow->mapped_data ?? []);

            if ($fw) {
                $screen = $fw->screen($import, $mappedData);
                if ($screen['reason'] !== null) {
                    $fw->quarantine($import, (array) ($importRow->raw_data ?? []), ValueParser::stripMeta($screen['data']), $screen['reason'], $importRow->row_number);
                    $importRow->delete();
                    $quarantined++;
                    continue;
                }
                $mappedData = $screen['data'];
            }

            try {
                DB::beginTransaction();
                $this->lastWriteWasDuplicate = false;
                $this->writeMappedData($import, $mappedData, $importRow->row_number);
                DB::commit();
                $importRow->delete();
                // WP3.1: a line that turned out to be loaded already is resolved,
                // but counted as a duplicate, not as a newly imported row.
                $this->lastWriteWasDuplicate ? $duplicates++ : $retried++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $importRow->update(['error_message' => $e->getMessage()]);
                $stillFailed++;
            }
        }

        $fw?->recordChunk($import);

        // Recompute failed_rows count
        $remaining = ImportRow::where('import_id', $import->id)->count();
        $status = $remaining === 0 ? Import::STATUS_COMPLETED : Import::STATUS_COMPLETED_WITH_ERRORS;
        $import->update([
            'failed_rows' => $remaining,
            'imported_rows' => $import->imported_rows + $retried,
            'duplicate_rows' => (int) $import->duplicate_rows + $duplicates,
            'quarantined_rows' => (int) $import->quarantined_rows + $quarantined,
            'status' => $status,
        ]);

        // WP3.5: the rows that landed now feed the daily aggregate and detection.
        if ($retried > 0) {
            try {
                app(\App\Services\Sales\SalesDailyAggregator::class)->aggregateForImport($import);
                app(\App\Services\Inventory\InventoryCurrentService::class)->refreshForImport($import);
                \App\Jobs\RunTenantDetectionJob::dispatch($import->tenant_id)->afterCommit();
            } catch (\Throwable $e) {
                Log::error('Post-retry aggregation/detection dispatch failed', ['import_id' => $import->id, 'error' => $e->getMessage()]);
            }
        }

        return ['retried' => $retried, 'still_failed' => $stillFailed, 'duplicates' => $duplicates, 'quarantined' => $quarantined];
    }

    /**
     * Mark an import stuck in "importing" for more than $minutes as failed.
     * Call from a scheduled command or health check.
     */
    public static function recoverStuckImports(int $minutes = 10, ?int $tenantId = null): int
    {
        return Import::where('status', Import::STATUS_IMPORTING)
            // WP1.3: a page load only ever heals its own tenant's imports.
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->update([
                'status'        => Import::STATUS_FAILED,
                'error_message' => 'Import timed out or the process was interrupted.',
            ]);
    }

    /**
     * Undo an import: delete every row it inserted (insert-based datasets only —
     * master data is upserted and therefore not reversible this way). Returns the
     * number of rows removed and marks the import as rolled back.
     */
    public function rollback(Import $import): int
    {
        $modelClass = match ($import->data_type) {
            Import::TYPE_SALES           => \App\Models\SalesTransaction::class,
            Import::TYPE_INVENTORY       => \App\Models\InventoryLevel::class,
            Import::TYPE_RETURNS         => \App\Models\SalesReturn::class,
            Import::TYPE_PURCHASE_ORDERS => \App\Models\PurchaseOrder::class,
            Import::TYPE_PROMOTIONS      => \App\Models\Promotion::class,
            Import::TYPE_WASTE           => \App\Models\WasteEvent::class,
            default                      => null,
        };

        if ($modelClass === null) {
            return 0;
        }

        // Incremental detection (Slice 1) — capture the (store, SKU) subjects
        // about to be removed, so the next run re-checks them. Collect BEFORE
        // the delete. Only the store-SKU-keyed datasets carry these columns.
        $dirtyKeys = [];
        if (in_array($import->data_type, [Import::TYPE_SALES, Import::TYPE_INVENTORY, Import::TYPE_RETURNS], true)) {
            $dirtyKeys = $modelClass::where('tenant_id', $import->tenant_id)
                ->where('import_id', $import->id)
                ->whereNotNull('store_id')
                ->whereNotNull('sku')
                ->distinct()
                ->get(['store_id', 'sku'])
                ->map(fn ($r) => ['store_id' => $r->store_id, 'sku' => $r->sku])
                ->all();
        }

        // WP3.5 (audit H27): remember the sales dates so the daily aggregate is
        // rebuilt for them after the delete (no phantom demand after an undo).
        $salesRange = $import->data_type === Import::TYPE_SALES
            ? $modelClass::where('tenant_id', $import->tenant_id)->where('import_id', $import->id)
                ->selectRaw('MIN(date) as mn, MAX(date) as mx')->first()
            : null;

        $deleted = $modelClass::where('tenant_id', $import->tenant_id)
            ->where('import_id', $import->id)
            ->delete();

        if ($salesRange && $salesRange->mn) {
            app(\App\Services\Sales\SalesDailyAggregator::class)->aggregateRange(
                (int) $import->tenant_id,
                \Illuminate\Support\Carbon::parse($salesRange->mn)->toDateString(),
                \Illuminate\Support\Carbon::parse($salesRange->mx)->toDateString(),
            );
        }

        // WP6.2: positions this import set fall back to their previous snapshot (or leave).
        if ($import->data_type === Import::TYPE_INVENTORY && ! empty($dirtyKeys)) {
            app(\App\Services\Inventory\InventoryCurrentService::class)->refreshKeys((int) $import->tenant_id, $dirtyKeys);
        }

        if (! empty($dirtyKeys)) {
            app(\App\Services\Detection\DirtyKeyRecorder::class)->record(
                $import->tenant_id,
                $dirtyKeys,
                \App\Models\DetectionDirtyKey::REASON_ROLLBACK,
            );
        }

        $import->update([
            'status'        => Import::STATUS_ROLLED_BACK,
            'error_message' => "Rolled back — {$deleted} row(s) removed.",
        ]);

        return $deleted;
    }

    /**
     * Cancel an import (typically one stuck in "importing" because the browser
     * tab was closed mid-run, or one that failed). Removes any rows it inserted
     * and marks it rolled back so the state is clean and it leaves the running
     * state. Safe against a concurrent poll: once status is no longer
     * "importing", the next processChunk() no-ops.
     */
    public function cancel(Import $import): int
    {
        if (in_array($import->data_type, Import::ROLLBACK_TYPES, true)) {
            return $this->rollback($import); // deletes inserted rows + sets rolled_back
        }

        $import->update([
            'status'        => Import::STATUS_ROLLED_BACK,
            'error_message' => 'Import cancelled.',
        ]);

        return 0;
    }

    /**
     * Process a whole import unattended (SFTP, API, queued jobs).
     *
     * WP3.7: the old single-pass path (whole file in memory, its own writers,
     * detection run inline) is retired — this drives the SAME chunked
     * screen → write pipeline the upload screen uses, to completion.
     */
    public function process(Import $import): void
    {
        try {
            $this->startChunkedImport($import);
            $guard = 0;
            do {
                $r = $this->processChunk($import->fresh());
            } while (! ($r['done'] ?? false) && ++$guard < 100000);
        } catch (\Throwable $e) {
            Log::error('Import processing failed', ['import_id' => $import->id, 'error' => $e->getMessage()]);
            $import->update(['status' => Import::STATUS_FAILED, 'error_message' => $e->getMessage()]);
            app(\App\Services\Storage\TenantStorage::class)->forgetImportCopy($import);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Chunked, poll-driven processing (memory-safe for large files)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Prepare an import for chunked, poll-driven processing. Resets counters,
     * clears any prior failed-row ledger, and flips status to importing. The
     * rows themselves are handled incrementally by processChunk().
     */
    public function startChunkedImport(Import $import): void
    {
        ImportRow::where('import_id', $import->id)->delete();
        DB::table('import_line_counters')->where('import_id', $import->id)->delete(); // WP3.1
        app(\App\Services\Storage\TenantStorage::class)->forgetImportCopy($import); // WP3.7
        // Fresh firewall run: drop any prior quarantine + quality for this import.
        QuarantinedRow::where('import_id', $import->id)->delete();
        ImportQuality::where('import_id', $import->id)->delete();

        $import->update([
            'status'           => Import::STATUS_IMPORTING,
            'imported_rows'    => 0,
            'failed_rows'      => 0,
            'quarantined_rows' => 0,
            'duplicate_rows'   => 0,
            'process_cursor'   => 0,
            'error_message'    => null,
            // WP3.6 (D6): with the firewall on, the whole file is screened first.
            'process_phase'    => $this->firewall() ? Import::PHASE_SCREEN : Import::PHASE_WRITE,
        ]);
    }

    /**
     * Process the next chunk of an in-progress import. Called repeatedly (e.g.
     * from a wire:poll) until it reports done. Each call reads only its window
     * of rows into memory and resolves stores/suppliers/products from primed
     * caches, so a 200k-row file never blows the memory limit or the request
     * timeout the way the old single-pass process() did.
     *
     * @return array{done: bool, processed: int, total: int, failed?: bool}
     */
    public function processChunk(Import $import, int $chunkSize = self::CHUNK_SIZE): array
    {
        $import->refresh();

        if ($import->status !== Import::STATUS_IMPORTING) {
            return ['done' => true, 'processed' => (int) $import->process_cursor, 'total' => (int) $import->total_rows];
        }

        $this->primeCaches($import->tenant_id);
        $this->parser = ValueParser::forImport($import);

        $columnMap = $import->columnMaps()
            ->where('is_skipped', false)
            ->whereNotNull('target_field')
            ->get()
            ->keyBy('source_header');

        $offset   = (int) $import->process_cursor;
        $filePath = app(\App\Services\Storage\TenantStorage::class)->importCopy($import);

        try {
            /** @var FileReaderService $reader */
            $reader = app(FileReaderService::class);
            $chunk  = $reader->readRange($filePath, $offset, $chunkSize, $this->dialectOf($import));
        } catch (\Throwable $e) {
            Log::error('Import chunk read failed', ['import_id' => $import->id, 'error' => $e->getMessage()]);
            $import->update(['status' => Import::STATUS_FAILED, 'error_message' => $e->getMessage()]);
            app(\App\Services\Storage\TenantStorage::class)->forgetImportCopy($import);

            return ['done' => true, 'processed' => $offset, 'total' => (int) $import->total_rows, 'failed' => true];
        }

        // WP3.5: claim this window atomically — a second poll (another tab, a
        // retried request) that read the same cursor loses and writes nothing.
        $newCursor = $offset + (int) $chunk['consumed'];
        if ((int) $chunk['consumed'] > 0) {
            $claimed = Import::whereKey($import->id)
                ->where('status', Import::STATUS_IMPORTING)
                ->where('process_cursor', $offset)
                ->update(['process_cursor' => $newCursor]);
            if ($claimed === 0) {
                $fresh = $import->fresh();

                return ['done' => $fresh->status !== Import::STATUS_IMPORTING, 'processed' => (int) $fresh->process_cursor, 'total' => (int) $fresh->total_rows];
            }
        }

        $imported    = 0;
        $failed      = 0;
        $quarantined = 0;
        $duplicates  = 0;
        $rowNumber   = $offset + 2; // 1-index + header row
        $isSales     = $import->data_type === Import::TYPE_SALES;
        // WP3.6 (D6): SCREEN = decide quality, write nothing; WRITE = load the rows
        // the screen passed (quarantine was already recorded while screening).
        $screening   = $import->process_phase === Import::PHASE_SCREEN && $this->firewall() !== null;

        // WP3.1: continue each receipt's line numbering from earlier chunks.
        $mappedRows = array_map(fn ($r) => $this->applyMap($columnMap, $r), $chunk['rows']);
        if ($isSales) {
            $this->loadLineCounters($import, $mappedRows);
        }

        $table    = $this->insertTableFor($import->data_type);
        $template = $table ? $this->insertTemplate($table) : [];
        $now      = now();
        $batch    = [];

        $this->firewall()?->begin($import);

        // Idempotency: identical file already ingested → skip the whole batch (no
        // re-promotion, so no duplicate canonical rows). Recorded as a duplicate batch.
        if (($fw = $this->firewall()) && $fw->isDuplicateBatch()) {
            $fw->recordChunk($import);
            $import->update([
                'status'         => Import::STATUS_ROLLED_BACK,
                'process_cursor' => (int) $import->total_rows,
                'error_message'  => 'Duplicate upload — identical file already ingested; skipped.',
            ]);

            return ['done' => true, 'processed' => (int) $import->total_rows, 'total' => (int) $import->total_rows];
        }

        // W9 (WP9.5): personal-data columns, found once on the first chunk.
        $this->firewall()?->inspectPii($import, $chunk['rows'], $columnMap->map(fn ($m) => $m->target_field)->all());

        foreach ($chunk['rows'] as $i => $rawRow) {
            $data = $mappedRows[$i];
            if ($isSales) {
                $data = $this->numberLine($data);
            }
            // WP3.2: dates / numbers read ONCE under the import's format.
            $data = $this->parser()->normalizeRow($import->data_type, $data);

            // Data Quality Firewall: cleanse + gate. Rejected rows are diverted to
            // quarantine and never reach the canonical tables.
            if ($fw = $this->firewall()) {
                $screen = $fw->screen($import, $data);
                if ($screen['reason'] !== null) {
                    if ($screening || $import->process_phase === null) {
                        $fw->quarantine($import, $rawRow, ValueParser::stripMeta($screen['data']), $screen['reason'], $rowNumber);
                        $quarantined++;
                    }
                    $rowNumber++;
                    continue;
                }
                $data = $screen['data'];
            }

            if ($screening) {
                $rowNumber++;
                continue;
            }

            try {
                if ($table !== null) {
                    // Insert-based type: build + collect for one bulk INSERT.
                    $attrs = array_merge($template, $this->buildInsertAttrs($import, $data, $rowNumber));
                    $attrs['created_at'] = $now;
                    $attrs['updated_at'] = $now;
                    $batch[] = ['attrs' => $attrs, 'row' => $rowNumber, 'raw' => $rawRow, 'mapped' => $data];
                    $imported++; // optimistic; reconciled if the bulk insert falls back
                } else {
                    // Master/upsert type (products, stores, suppliers, users): per-row.
                    $this->writeMappedData($import, $data, $rowNumber);
                    $imported++;
                }
            } catch (\Throwable $e) {
                $failed++;
                ImportRow::create([
                    'import_id'     => $import->id,
                    'tenant_id'     => $import->tenant_id,
                    'row_number'    => $rowNumber,
                    'raw_data'      => $this->firewall()?->maskRaw($rawRow) ?? $rawRow,   // W9 (WP9.5)
                    'mapped_data'   => $data,
                    'error_message' => $e->getMessage(),
                    'status'        => ImportRow::STATUS_PENDING,
                ]);
            }
            $rowNumber++;
        }

        // One multi-row write per sub-batch — the core speedup for large files.
        // WP3.1/WP3.5: each table is written against its natural key, so a
        // re-sent row is skipped (events) or updated (snapshots), never doubled.
        if ($table !== null && ! empty($batch)) {
            foreach (array_chunk($batch, self::INSERT_BATCH) as $slice) {
                try {
                    $skipped = $this->writeBatch($table, array_column($slice, 'attrs'));
                    $imported -= $skipped;
                    $duplicates += $skipped;
                } catch (\Throwable $e) {
                    // A single bad value aborts the whole multi-row write. Fall
                    // back to per-row so the good rows still land and the bad ones
                    // are captured in the failed-row ledger instead of vanishing.
                    foreach ($slice as $entry) {
                        try {
                            if ($this->writeBatch($table, [$entry['attrs']]) > 0) {
                                $imported--;
                                $duplicates++;
                            }
                        } catch (\Throwable $rowError) {
                            $imported--;
                            $failed++;
                            ImportRow::create([
                                'import_id'     => $import->id,
                                'tenant_id'     => $import->tenant_id,
                                'row_number'    => $entry['row'],
                                'raw_data'      => $this->firewall()?->maskRaw($entry['raw']) ?? $entry['raw'],
                                'mapped_data'   => $entry['mapped'],
                                'error_message' => $rowError->getMessage(),
                                'status'        => ImportRow::STATUS_PENDING,
                            ]);
                        }
                    }
                }
            }
        }

        // Incremental detection (Slice 1) — queue the (store, SKU) subjects this
        // chunk touched so the next detection run can scan only what changed.
        // Ships dark: the queue is populated but not yet consumed. Best-effort.
        if ($table !== null && ! empty($batch)) {
            $dirty = [];
            foreach ($batch as $entry) {
                $a = $entry['attrs'];
                if (isset($a['store_id'], $a['sku']) && $a['store_id'] !== null && $a['sku'] !== null) {
                    $dirty[$a['store_id'] . '|' . $a['sku']] = ['store_id' => $a['store_id'], 'sku' => $a['sku']];
                }
            }
            if (! empty($dirty)) {
                app(\App\Services\Detection\DirtyKeyRecorder::class)->record(
                    $import->tenant_id,
                    array_values($dirty),
                    \App\Models\DetectionDirtyKey::REASON_IMPORT,
                );
            }
        }

        if ($screening) {
            $this->firewall()->recordChunk($import, alert: false);
        } elseif ($import->process_phase === Import::PHASE_WRITE) {
            $this->firewall()?->recordWarnings($import);
        } else {
            $this->firewall()?->recordChunk($import); // an import started before WP3.6
        }

        if ($isSales) {
            $this->persistLineCounters($import);
        }

        // Counters as increments (never read-modify-write).
        Import::whereKey($import->id)->update([
            'imported_rows'    => DB::raw('imported_rows + ' . (int) $imported),
            'failed_rows'      => DB::raw('failed_rows + ' . (int) $failed),
            'quarantined_rows' => DB::raw('quarantined_rows + ' . (int) $quarantined),
            'duplicate_rows'   => DB::raw('duplicate_rows + ' . (int) $duplicates),
            'updated_at'       => now(),
        ]);
        $import->refresh();

        $done = $chunk['eof']
            || (int) $chunk['consumed'] === 0
            || ($import->total_rows > 0 && $newCursor >= $import->total_rows);

        if ($done && $screening) {
            return $this->finishScreening($import);
        }

        if ($done) {
            $this->finalizeChunkedImport($import);

            return ['done' => true, 'processed' => (int) $import->process_cursor, 'total' => (int) $import->total_rows];
        }

        return ['done' => false, 'processed' => $newCursor, 'total' => (int) $import->total_rows];
    }

    /**
     * W9 (WP9.1) — dress rehearsal. Re-read a stored file with its saved
     * column map and run every row through the firewall exactly as a load
     * would — twice: with the gates as configured, and with every gate on
     * (strict validation + referential) — plus the feed and personal-data
     * checks. NOTHING is written: the whole pass runs in a transaction that
     * is rolled back.
     *
     * @return array<string,mixed>
     */
    public function rehearse(Import $import, ?int $limit = null): array
    {
        $started = microtime(true);
        $columnMap = $import->columnMaps()->where('is_skipped', false)->whereNotNull('target_field')->get()->keyBy('source_header');
        if ($columnMap->isEmpty()) {
            throw new \RuntimeException("Import #{$import->id} has no saved column mapping.");
        }

        DB::beginTransaction();
        try {
            try {
                $path = app(\App\Services\Storage\TenantStorage::class)->importCopy($import);
            } catch (\Throwable) {
                $path = null;
            }
            if ($path === null || ! is_file($path) || filesize($path) === 0) {
                throw new \RuntimeException("Import #{$import->id}: the stored file is gone (disk [{$import->disk}]) — nothing to rehearse.");
            }
            $this->primeCaches($import->tenant_id);
            $this->parser = ValueParser::forImport($import);
            $this->lineCounters = [];
            $this->touchedReceipts = [];

            $modes = ['configured' => [], 'all_gates' => ['strict' => true, 'referential_gate' => true]];
            $fws = $samples = $rejected = [];
            foreach ($modes as $mode => $overrides) {
                $fws[$mode] = app(DataQualityFirewall::class);
                $fws[$mode]->beginDry($import, $overrides);
                $rejected[$mode] = 0;
            }

            $reader = app(FileReaderService::class);
            $offset = 0;
            $rows = 0;
            $firstRows = [];
            $isSales = $import->data_type === Import::TYPE_SALES;
            do {
                $chunk = $reader->readRange($path, $offset, self::CHUNK_SIZE, $this->dialectOf($import));
                if ($firstRows === []) {
                    $firstRows = array_slice($chunk['rows'], 0, 500);
                }
                foreach ($chunk['rows'] as $raw) {
                    $rows++;
                    $data = $this->applyMap($columnMap, $raw);
                    if ($isSales) {
                        $data = $this->numberLine($data);
                    }
                    $data = $this->parser()->normalizeRow($import->data_type, $data);
                    foreach ($fws as $mode => $fw) {
                        $s = $fw->screen($import, $data);
                        if ($s['reason'] !== null) {
                            $fw->dryReject($s['reason']);
                            $rejected[$mode]++;
                            if (count($samples[$mode][$s['reason']] ?? []) < 3) {
                                $samples[$mode][$s['reason']][] = ['row' => $rows + 1, 'raw' => $raw];
                            }
                        }
                    }
                    if ($limit && $rows >= $limit) {
                        break 2;
                    }
                }
                $offset += (int) $chunk['consumed'];
            } while (! $chunk['eof'] && (int) $chunk['consumed'] > 0);

            $targets = $columnMap->map(fn ($m) => $m->target_field)->all();
            $pii = \App\Services\DataQuality\PiiGuard::detect($firstRows, $targets);
            $decider = app(\App\Services\DataQuality\BatchDecisionService::class);
            $out = [];
            foreach ($fws as $mode => $fw) {
                $t = $fw->dryTally($import);
                $decision = $decider->decide($import->data_type, $t['promoted'], $rejected[$mode], false);
                $warnings = array_diff_key($t['reason_counts'], $samples[$mode] ?? []);
                $out[$mode] = [
                    'passed'      => $t['promoted'],
                    'quarantined' => $rejected[$mode],
                    'cleansed'    => $t['cleansed'],
                    'state'       => $decision['state'],
                    'decision'    => $decision['decision'],
                    'rejections'  => array_intersect_key($t['reason_counts'], $samples[$mode] ?? []),
                    'warnings'    => $warnings,
                    'samples'     => array_map(fn ($list) => array_map(fn ($s) => ['row' => $s['row'], 'raw' => \App\Services\DataQuality\PiiGuard::maskRow($s['raw'], $pii)], $list), $samples[$mode] ?? []),
                ];
            }

            $monitor = app(\App\Services\DataQuality\FeedMonitor::class);
            $contract = $monitor->contractFor($import);
            $headers = array_keys($firstRows[0] ?? []) ?: \App\Services\DataQuality\FeedMonitor::headersOf($import);

            return [
                'import'    => ['id' => $import->id, 'file' => $import->original_filename, 'data_type' => $import->data_type,
                    'feed' => \App\Services\DataQuality\FeedMonitor::feedKeyOf($import), 'loaded_at' => (string) $import->created_at, 'status' => $import->status],
                'rows'      => $rows,
                'limited'   => (bool) ($limit && $rows >= $limit),
                'modes'     => $out,
                'feed'      => ['established' => $contract->exists && $contract->batches_seen >= \App\Services\DataQuality\FeedMonitor::minHistory(),
                    'batches_seen' => (int) $contract->batches_seen, 'issues' => $monitor->check($contract, $headers, $rows)],
                'pii'       => $pii,
                'seconds'   => round(microtime(true) - $started, 1),
            ];
        } finally {
            DB::rollBack();
            app(\App\Services\Storage\TenantStorage::class)->forgetImportCopy($import);
        }
    }

    /**
     * WP3.6 — quarantine triage. Re-screen open quarantined rows against the
     * CURRENT rules, aliases and product master (after a fix) and load the ones
     * that now pass; with $force, load them despite their reason (audited). A
     * row without its key can never be forced. Loaded rows feed the daily
     * aggregate and detection like any import.
     *
     * @return array{promoted: int, still: int}
     */
    public function reprocessQuarantined(\Illuminate\Support\Collection $rows, bool $force = false, ?User $by = null): array
    {
        $promoted = 0;
        $still = 0;

        foreach ($rows->groupBy('import_id') as $importId => $group) {
            $import = Import::find($importId);
            if (! $import) {
                continue;
            }
            $this->parser = ValueParser::forImport($import);
            $this->primeCaches((int) $import->tenant_id);
            $columnMap = $import->columnMaps()->where('is_skipped', false)->whereNotNull('target_field')->get()->keyBy('source_header');
            $fw = $this->firewall();
            $fw?->begin($import);
            $wrote = 0;

            foreach ($group as $q) {
                if ($q->status !== QuarantinedRow::STATUS_OPEN) {
                    continue;
                }
                $data = $this->applyMap($columnMap, (array) $q->raw_data);
                if (isset($q->cleansed_data['line_no'])) {
                    $data['line_no'] = (string) $q->cleansed_data['line_no'];
                }
                $data = $this->parser()->normalizeRow($import->data_type, $data);

                if ($fw) {
                    $screen = $fw->screen($import, $data);
                    $reason = $screen['reason'];
                    if ($reason !== null && (! $force || $reason === \App\Services\DataQuality\Reasons::MISSING_KEY)) {
                        $q->update([
                            'reason_code'   => $reason,
                            'severity'      => \App\Services\DataQuality\Reasons::severity($reason),
                            'message'       => \App\Services\DataQuality\Reasons::label($reason),
                            'cleansed_data' => ValueParser::stripMeta($screen['data']),
                        ]);
                        $still++;
                        continue;
                    }
                    $data = $screen['data'];
                }

                try {
                    DB::transaction(fn () => $this->writeMappedData($import, $data, (int) $q->row_number));
                    $q->update(['status' => $force ? QuarantinedRow::STATUS_PROMOTED : QuarantinedRow::STATUS_RESOLVED]);
                    $promoted++;
                    $wrote++;
                } catch (\Throwable $e) {
                    $q->update(['message' => Str::limit($e->getMessage(), 250)]);
                    $still++;
                }
            }

            if ($wrote > 0) {
                Import::whereKey($import->id)->update([
                    'imported_rows'    => DB::raw('imported_rows + ' . $wrote),
                    'quarantined_rows' => DB::raw('GREATEST(quarantined_rows - ' . $wrote . ', 0)'),
                ]);
                try {
                    app(\App\Services\Sales\SalesDailyAggregator::class)->aggregateForImport($import);
                    app(\App\Services\Inventory\InventoryCurrentService::class)->refreshForImport($import);
                    \App\Jobs\RunTenantDetectionJob::dispatch($import->tenant_id)->afterCommit();
                } catch (\Throwable $e) {
                    Log::error('Post-quarantine aggregation/detection dispatch failed', ['import_id' => $import->id, 'error' => $e->getMessage()]);
                }
                if ($force) {
                    try {
                        \App\Models\AuditLog::create([
                            'tenant_id'   => $import->tenant_id,
                            'user_id'     => $by?->id,
                            'event_type'  => 'quarantine_promoted',
                            'description' => "{$wrote} quarantined row(s) of import #{$import->id} promoted despite their data-quality reason.",
                        ]);
                    } catch (\Throwable) {
                        // best-effort
                    }
                }
            }
        }

        return ['promoted' => $promoted, 'still' => $still];
    }

    /**
     * WP3.6 (audit H28): the batch verdict counts rows that FAILED TO WRITE, not
     * just rows the firewall passed — a file whose every row fails at the
     * database is no longer "100% clean". An admin override stands.
     */
    private function rescoreAfterWrite(Import $import): void
    {
        $failed = (int) $import->failed_rows;
        $q = ImportQuality::where('import_id', $import->id)->first();
        if ($failed === 0 || ! $q || $q->overridden_at !== null || $q->state === ImportQuality::STATE_DUPLICATE) {
            return;
        }

        $decision = app(\App\Services\DataQuality\BatchDecisionService::class)->decide(
            $import->data_type,
            max(0, (int) $q->rows_promoted - $failed),
            (int) $q->rows_quarantined + $failed,
            false,
        );
        $wasRed = $q->state === ImportQuality::STATE_RED;
        $q->update(['state' => $decision['state'], 'decision' => $decision['decision'] . " ({$failed} row(s) failed to load.)", 'blocked' => $decision['blocked']]);

        if ($decision['state'] === ImportQuality::STATE_RED && ! $wasRed) {
            app(\App\Services\DataQuality\ReadinessAlerter::class)->redBatch($q->fresh());
        }
    }

    /**
     * WP3.6 (D6): the whole file has been screened. A RED batch is HELD —
     * nothing was written — and the admins are alerted; otherwise the write
     * pass starts from the top.
     */
    private function finishScreening(Import $import): array
    {
        $q = ImportQuality::where('import_id', $import->id)->first();
        $total = (int) max($import->total_rows, $import->process_cursor);

        if ($q && $q->state === ImportQuality::STATE_RED) {
            $import->update([
                'status'        => Import::STATUS_HELD,
                'total_rows'    => $total,
                'error_message' => 'Held — ' . $q->decision . ' Nothing was loaded. An admin can promote it anyway.',
            ]);
            app(\App\Services\DataQuality\ReadinessAlerter::class)->redBatch($q);
            app(\App\Services\Storage\TenantStorage::class)->forgetImportCopy($import);
            app(\App\Services\DataQuality\FeedMonitor::class)->recordBatch($import);   // W9: it was delivered

            return ['done' => true, 'processed' => $total, 'total' => $total];
        }

        // W9 (WP9.2): an automated sales / stock file far below its usual size
        // is held before anything is written — a half-delivered file would
        // otherwise read as a sales or stock collapse.
        $import->total_rows = $total;
        if ($reason = app(\App\Services\DataQuality\FeedMonitor::class)->holdAsPartial($import)) {
            $import->update(['status' => Import::STATUS_HELD, 'total_rows' => $total, 'error_message' => $reason]);
            $q?->update(['state' => ImportQuality::STATE_RED, 'blocked' => true, 'decision' => $reason]);
            app(\App\Services\Storage\TenantStorage::class)->forgetImportCopy($import);
            app(\App\Services\DataQuality\FeedMonitor::class)->recordBatch($import);

            return ['done' => true, 'processed' => $total, 'total' => $total];
        }

        DB::table('import_line_counters')->where('import_id', $import->id)->delete();
        $import->update(['process_phase' => Import::PHASE_WRITE, 'process_cursor' => 0, 'total_rows' => $total]);

        return ['done' => false, 'processed' => 0, 'total' => $total];
    }

    /**
     * WP3.6 (D6): an admin loads a held (RED) batch anyway. Audited; the quality
     * record keeps the original verdict in its decision text.
     */
    public function promoteHeld(Import $import, \App\Models\User $by): void
    {
        if ($import->status !== Import::STATUS_HELD) {
            throw new \RuntimeException('Only a held import can be promoted.');
        }

        $q = ImportQuality::where('import_id', $import->id)->first();
        $q?->update([
            'state'         => ImportQuality::STATE_AMBER,
            'blocked'       => false,
            'decision'      => "Promoted by {$by->name} despite: " . $q->decision,
            'overridden_by' => $by->id,
            'overridden_at' => now(),
        ]);

        try {
            \App\Models\AuditLog::create([
                'tenant_id'   => $import->tenant_id,
                'user_id'     => $by->id,
                'event_type'  => 'import_promoted_despite_quality',
                'description' => "Import #{$import->id} ({$import->original_filename}) promoted despite a RED quality decision.",
            ]);
        } catch (\Throwable) {
            // best-effort
        }

        DB::table('import_line_counters')->where('import_id', $import->id)->delete();
        $import->update([
            'status'         => Import::STATUS_IMPORTING,
            'process_phase'  => Import::PHASE_WRITE,
            'process_cursor' => 0,
            'error_message'  => null,
        ]);
    }

    /**
     * Wrap up a chunked import: set the final status, record the ingestion run,
     * and run anomaly detection across the tenant.
     */
    private function finalizeChunkedImport(Import $import): void
    {
        $import->refresh();

        // WP3.6 (audit H28): quarantined rows are errors too.
        $status = ($import->failed_rows > 0 || $import->quarantined_rows > 0)
            ? Import::STATUS_COMPLETED_WITH_ERRORS
            : Import::STATUS_COMPLETED;

        $this->rescoreAfterWrite($import);

        $total = max(
            (int) $import->total_rows,
            (int) $import->imported_rows + (int) $import->failed_rows + (int) $import->duplicate_rows
        );

        $import->update([
            'status'     => $status,
            'total_rows' => $total,
        ]);

        DB::table('import_line_counters')->where('import_id', $import->id)->delete(); // WP3.1

        // W9 (WP9.2): check the batch against its feed, then learn from it.
        app(\App\Services\DataQuality\FeedMonitor::class)->recordBatch($import);

        // Slice 5 — learn this confirmed mapping so the next same-shaped import auto-maps.
        try {
            \App\Models\MappingMemory::rememberFromImport($import);
        } catch (\Throwable $e) {
            Log::warning('Mapping memory learn failed', ['import_id' => $import->id, 'error' => $e->getMessage()]);
        }

        $this->recordIngestionRun(
            $import,
            $total,
            (int) $import->imported_rows,
            (int) $import->failed_rows,
            $import->created_at
        );

        try {
            // Keep the sales_daily aggregate current for this import's date range
            // (memory-safe, incremental) so detection reads the aggregate, not raw POS.
            app(\App\Services\Sales\SalesDailyAggregator::class)->aggregateForImport($import);
            // WP6.2: the current stock positions this inventory load moved.
            app(\App\Services\Inventory\InventoryCurrentService::class)->refreshForImport($import);
            // WP6.5: master data moves the canonical hierarchies with it.
            if (in_array($import->data_type, [Import::TYPE_PRODUCTS, Import::TYPE_STORES, Import::TYPE_SUPPLIERS], true)) {
                app(\App\Services\Platform\HierarchySync::class)->syncTenant((int) $import->tenant_id);
            }
            // WP6.4: that dataset's health, recomputed off the request.
            if ($dataset = self::HEALTH_DATASET[$import->data_type] ?? null) {
                \App\Jobs\DataHealth\ComputeDataHealthJob::dispatch($import->tenant_id, $dataset)->afterCommit();
            }
            // W9 (WP9.3): the semantic data checks, after the aggregate moved.
            \App\Jobs\DataQuality\RunDataQualityChecksJob::dispatch((int) $import->tenant_id)->afterCommit();

            // Detection can take minutes on large data, so it runs off the web
            // request as a queued job (requires a queue worker). Incremental by
            // default (WP1.2 / audit H1 restores this) — scans only the SKUs this
            // import touched (dirty keys recorded per chunk) plus open subjects;
            // the nightly full scan is the backstop. afterCommit so a worker never
            // reads rows before this import commits.
            \App\Jobs\RunTenantDetectionJob::dispatch(
                $import->tenant_id,
                config('detection.import_trigger_mode', 'incremental')
            )->afterCommit();
        } catch (\Throwable $e) {
            Log::error('Post-import aggregation/detection dispatch failed', ['import_id' => $import->id, 'error' => $e->getMessage()]);
        }
    }

    /** WP6.4: import type → Data Health dataset. */
    private const HEALTH_DATASET = [
        Import::TYPE_SALES           => \App\Models\DataHealthSnapshot::DATASET_SALES,
        Import::TYPE_INVENTORY       => \App\Models\DataHealthSnapshot::DATASET_INVENTORY,
        Import::TYPE_PURCHASE_ORDERS => \App\Models\DataHealthSnapshot::DATASET_PURCHASE_ORDERS,
        Import::TYPE_PRODUCTS        => \App\Models\DataHealthSnapshot::DATASET_PRODUCTS,
        Import::TYPE_STORES          => \App\Models\DataHealthSnapshot::DATASET_STORES,
        Import::TYPE_SUPPLIERS       => \App\Models\DataHealthSnapshot::DATASET_SUPPLIERS,
    ];

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Write already-mapped data (used by retryRows to avoid re-reading the file).
     */
    private function writeMappedData(Import $import, array $data, int $rowNumber): void
    {
        $data = $this->prepareForWrite($import, $data);

        match ($import->data_type) {
            Import::TYPE_SALES           => $this->writeSalesTransaction($import, $data, $rowNumber),
            Import::TYPE_INVENTORY       => $this->writeInventoryLevel($import, $data, $rowNumber),
            Import::TYPE_PRODUCTS        => $this->writeProduct($import, $data, $rowNumber),
            Import::TYPE_PURCHASE_ORDERS => $this->writePurchaseOrder($import, $data, $rowNumber),
            Import::TYPE_STORES          => $this->writeStore($import, $data, $rowNumber),
            Import::TYPE_SUPPLIERS       => $this->writeSupplier($import, $data, $rowNumber),
            Import::TYPE_USERS           => $this->writeUser($import, $data, $rowNumber),
            Import::TYPE_RETURNS         => $this->writeReturn($import, $data, $rowNumber),
            Import::TYPE_PROMOTIONS      => $this->writePromotion($import, $data, $rowNumber),
            Import::TYPE_WASTE           => $this->writeWaste($import, $data, $rowNumber),
            default                      => throw new \InvalidArgumentException("Unknown data type: {$import->data_type}"),
        };
    }

    /**
     * WP3.5 — natural keys. Events (sales lines, returns) are skipped when
     * already loaded; snapshots (inventory positions, PO lines) are updated to
     * the newest values (and re-owned by the newest import). Until a table's
     * unique index exists (deployed separately, after de-duplication) it is a
     * plain insert, as before.
     */
    private const NATURAL_KEYS = [
        'inventory_levels' => ['tenant_id', 'store_id', 'sku', 'as_of_date', 'batch_ref'],
        'purchase_orders'  => ['tenant_id', 'po_number', 'sku', 'store_id'],
        'promotions'       => ['tenant_id', 'promotion_ref', 'sku', 'store_id'],   // W10: a re-sent calendar updates
        'waste_events'     => ['tenant_id', 'date', 'sku', 'store_id', 'reason', 'waste_ref'],   // W11
    ];
    private const NATURAL_KEY_INDEXES = [
        'sales_transactions' => 'sales_tx_receipt_line_unique',
        'sales_returns'      => 'sales_returns_natural_key',
        'inventory_levels'   => 'inventory_levels_natural_key',
        'purchase_orders'    => 'purchase_orders_natural_key',
        'promotions'         => 'promotions_natural_key',
        'waste_events'       => 'waste_events_natural_key',
    ];

    /** @var array<string,bool> */
    private static array $naturalKeyReady = [];

    private function hasNaturalKey(string $table): bool
    {
        if (! isset(self::NATURAL_KEY_INDEXES[$table])) {
            return false;
        }
        if (! array_key_exists($table, self::$naturalKeyReady)) {
            try {
                self::$naturalKeyReady[$table] = DB::getDriverName() === 'pgsql'
                    && (bool) DB::selectOne(
                        'select 1 as x from pg_class c join pg_index i on i.indexrelid = c.oid where c.relname = ? and i.indisvalid',
                        [self::NATURAL_KEY_INDEXES[$table]]
                    );
            } catch (\Throwable) {
                self::$naturalKeyReady[$table] = false;
            }
        }

        return self::$naturalKeyReady[$table];
    }

    /** For tests / after a migration in the same process. */
    public static function forgetNaturalKeys(): void
    {
        self::$naturalKeyReady = [];
    }

    /**
     * Write rows to a fact table against its natural key.
     *
     * @return int  rows NOT newly written (already loaded, or a later row in
     *              the same batch superseded them)
     */
    private function writeBatch(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        if (! $this->hasNaturalKey($table)) {
            DB::table($table)->insert($rows);

            return 0;
        }

        if (! isset(self::NATURAL_KEYS[$table])) {
            // Events: skip what is already there.
            return count($rows) - DB::table($table)->insertOrIgnore($rows);
        }

        $keys = self::NATURAL_KEYS[$table];
        $additive = self::ADDITIVE[$table] ?? [];

        // Snapshots. Within ONE file, rows sharing a key are parts of one position
        // (bins / lots without a lot id): quantities add up. A later file replaces.
        $unique = [];
        $merged = 0;
        foreach ($rows as $r) {
            $k = implode("\x1F", array_map(fn ($c) => var_export($r[$c] ?? null, true), $keys));
            if (isset($unique[$k]) && $additive !== []) {
                $unique[$k] = $this->mergePosition($unique[$k], $r, $additive);
                $merged++;
                continue;
            }
            unset($unique[$k]);
            $unique[$k] = $r;
        }
        $unique = array_values($unique);

        $update = [];
        foreach (array_diff(array_keys($unique[0]), array_merge($keys, ['created_at'])) as $col) {
            $update[$col] = in_array($col, $additive, true)
                // Same import (a later chunk of the same file) → add; another import → replace.
                ? DB::raw("CASE WHEN {$table}.import_id IS NOT DISTINCT FROM EXCLUDED.import_id THEN COALESCE({$table}.{$col}, 0) + COALESCE(EXCLUDED.{$col}, 0) ELSE EXCLUDED.{$col} END")
                : DB::raw("EXCLUDED.{$col}");
        }
        DB::table($table)->upsert($unique, $keys, $update);

        // Merged parts of a position were loaded (as part of the sum), not skipped.
        return $additive === [] ? count($rows) - count($unique) : 0;
    }

    /** WP3.5: quantities that add up across the parts of one inventory position. */
    private const ADDITIVE = [
        'inventory_levels' => ['on_hand_qty', 'on_order_qty', 'inventory_value', 'allocated_qty', 'in_transit_qty'],
        // W11: lines of one file that share a key (same SKU, store, day, reason) add up.
        'waste_events'     => ['quantity', 'value'],
    ];

    private function mergePosition(array $a, array $b, array $additive): array
    {
        foreach ($additive as $col) {
            if (($a[$col] ?? null) !== null || ($b[$col] ?? null) !== null) {
                $a[$col] = (float) ($a[$col] ?? 0) + (float) ($b[$col] ?? 0);
            }
        }
        foreach (['reorder_point', 'safety_stock'] as $col) {
            if (isset($b[$col])) {
                $a[$col] = max((float) ($a[$col] ?? 0), (float) $b[$col]);
            }
        }

        return $a;
    }

    /**
     * The DB table for a batch-insertable (insert-based) data type, or null for
     * upsert/master types (products, stores, suppliers, users) which stay per-row.
     */
    private function insertTableFor(string $dataType): ?string
    {
        return match ($dataType) {
            Import::TYPE_SALES           => 'sales_transactions',
            Import::TYPE_INVENTORY       => 'inventory_levels',
            Import::TYPE_PURCHASE_ORDERS => 'purchase_orders',
            Import::TYPE_RETURNS         => 'sales_returns',
            Import::TYPE_PROMOTIONS      => 'promotions',
            Import::TYPE_WASTE           => 'waste_events',
            default                      => null,
        };
    }

    /**
     * Full column template per insert table so every batched row has an
     * identical key set (a multi-row INSERT requires uniform columns).
     */
    private function insertTemplate(string $table): array
    {
        return match ($table) {
            'sales_transactions' => [
                'tenant_id' => null, 'import_id' => null, 'store_id' => null, 'product_id' => null,
                'transaction_id' => null, 'line_no' => null, 'row_hash' => null, 'date' => null, 'sku' => null, 'location' => null,
                'quantity' => null, 'unit_price' => null, 'total_amount' => null, 'discount' => null, 'payment_method' => null,
                // WP3.4
                'channel' => null, 'cost_amount' => null, 'currency' => null, 'customer_ref' => null, 'promotion_ref' => null,
            ],
            'inventory_levels' => [
                'tenant_id' => null, 'import_id' => null, 'store_id' => null, 'product_id' => null,
                'sku' => null, 'location' => null, 'on_hand_qty' => null, 'reorder_point' => null,
                'as_of_date' => null, 'on_order_qty' => null, 'inventory_value' => null,
                // WP3.4
                'safety_stock' => null, 'allocated_qty' => null, 'in_transit_qty' => null, 'unit_cost' => null,
                'batch_ref' => null, 'expiry_date' => null,
            ],
            'purchase_orders' => [
                'tenant_id' => null, 'import_id' => null, 'supplier_id' => null, 'store_id' => null, 'product_id' => null,
                'po_number' => null, 'supplier' => null, 'sku' => null, 'qty_ordered' => null, 'qty_received' => null,
                'unit_cost' => null, 'order_date' => null, 'expected_date' => null, 'received_date' => null,
                'location' => null, 'open_qty' => null, 'late_days' => null, 'fill_rate' => null,
                // WP3.4
                'currency' => null, 'status' => null, 'buyer' => null,
            ],
            'sales_returns' => [
                'tenant_id' => null, 'import_id' => null, 'store_id' => null, 'product_id' => null,
                'return_id' => null, 'date' => null, 'sku' => null, 'location' => null,
                'quantity' => null, 'value' => null, 'reason' => null,
                // WP3.4
                'channel' => null, 'condition' => null, 'original_transaction_ref' => null,
            ],
            'waste_events' => [
                'tenant_id' => null, 'import_id' => null, 'store_id' => null, 'product_id' => null,
                'date' => null, 'sku' => null, 'location' => null, 'quantity' => null, 'value' => null,
                'reason' => null, 'waste_ref' => null,
            ],
            'promotions' => [
                'tenant_id' => null, 'import_id' => null, 'store_id' => null, 'product_id' => null,
                'promotion_ref' => null, 'name' => null, 'sku' => null, 'location' => null,
                'starts_on' => null, 'ends_on' => null, 'mechanic' => null, 'discount_pct' => null, 'promo_price' => null,
            ],
            default => [],
        };
    }

    /**
     * Build the attribute array for one insert-based row (validation throws on
     * a bad row so the caller can capture it in the failed-row ledger).
     */
    private function buildInsertAttrs(Import $import, array $data, int $row): array
    {
        $data = $this->prepareForWrite($import, $data);

        return match ($import->data_type) {
            Import::TYPE_SALES           => $this->buildSalesTransactionAttrs($import, $data, $row),
            Import::TYPE_INVENTORY       => $this->buildInventoryLevelAttrs($import, $data, $row),
            Import::TYPE_PURCHASE_ORDERS => $this->buildPurchaseOrderAttrs($import, $data, $row),
            Import::TYPE_RETURNS         => $this->buildReturnAttrs($import, $data, $row),
            Import::TYPE_PROMOTIONS      => $this->buildPromotionAttrs($import, $data, $row),
            Import::TYPE_WASTE           => $this->buildWasteAttrs($import, $data, $row),
            default                      => throw new \InvalidArgumentException("Not a batch-insert type: {$import->data_type}"),
        };
    }

    /**
     * WP3.1 (audit C2): a sales row is one receipt LINE. Always insert — never
     * update another line of the same receipt. A (receipt, line) that is already
     * loaded is skipped as a duplicate (idempotent re-import), not overwritten.
     */
    private function writeSalesTransaction(Import $import, array $data, int $row): void
    {
        $attrs = $this->buildSalesTransactionAttrs($import, $data, $row);

        // A retried row from before line numbering (or from a path that did not
        // number it) takes the receipt's next free line.
        if ($attrs['transaction_id'] !== null && $attrs['line_no'] === null) {
            $attrs['line_no'] = 1 + (int) SalesTransaction::where('tenant_id', $attrs['tenant_id'])
                ->where('transaction_id', $attrs['transaction_id'])
                ->max('line_no');
        }

        $now = now();
        $this->lastWriteWasDuplicate = $this->hasNaturalKey('sales_transactions')
            ? DB::table('sales_transactions')->insertOrIgnore($attrs + ['created_at' => $now, 'updated_at' => $now]) === 0
            : ! DB::table('sales_transactions')->insert($attrs + ['created_at' => $now, 'updated_at' => $now]);
    }

    /**
     * WP3.1 (D10): give a sales row its line number. A mapped `line_no` wins;
     * otherwise the line is numbered by its order of appearance within its
     * receipt in the file (1, 2, 3…), so the same receipt re-sent in the same
     * order maps onto the same lines. Every row of a receipt advances the
     * counter — also rows that later fail or are quarantined — so numbering
     * depends only on the file, never on what happened to earlier rows.
     */
    private function numberLine(array $data): array
    {
        $receipt = trim((string) ($data['transaction_id'] ?? ''));
        if ($receipt === '') {
            return $data;
        }

        $this->lineCounters[$receipt] = ($this->lineCounters[$receipt] ?? 0) + 1;
        $this->touchedReceipts[$receipt] = true;

        if (trim((string) ($data['line_no'] ?? '')) === '') {
            $data['line_no'] = (string) $this->lineCounters[$receipt];
        }

        return $data;
    }

    /** Load the counters of the receipts in this chunk that earlier chunks already saw. */
    private function loadLineCounters(Import $import, array $mappedRows): void
    {
        $this->lineCounters = [];
        $this->touchedReceipts = [];

        $receipts = array_values(array_unique(array_filter(array_map(
            fn ($d) => trim((string) ($d['transaction_id'] ?? '')),
            $mappedRows
        ), fn ($r) => $r !== '')));

        foreach (array_chunk($receipts, 5000) as $slice) {
            DB::table('import_line_counters')
                ->where('import_id', $import->id)
                ->whereIn('transaction_id', $slice)
                ->pluck('last_line', 'transaction_id')
                ->each(function ($last, $receipt) {
                    $this->lineCounters[(string) $receipt] = (int) $last;
                });
        }
    }

    private function persistLineCounters(Import $import): void
    {
        $rows = [];
        foreach (array_keys($this->touchedReceipts) as $receipt) {
            $rows[] = ['import_id' => $import->id, 'transaction_id' => (string) $receipt, 'last_line' => $this->lineCounters[$receipt]];
        }
        foreach (array_chunk($rows, 2000) as $slice) {
            DB::table('import_line_counters')->upsert($slice, ['import_id', 'transaction_id'], ['last_line']);
        }
        $this->touchedReceipts = [];
    }

    private function buildSalesTransactionAttrs(Import $import, array $data, int $row): array
    {
        $this->requireFields($data, ['date', 'sku', 'quantity'], $row);

        $location = $data['location'] ?? null;

        $attrs = [
            'tenant_id'      => $import->tenant_id,
            'import_id'      => $import->id,
            'date'           => $this->parseDate($data['date'], $row),
            'sku'            => $this->str($data['sku'] ?? null, 'sku', $row),
            'location'       => $location,
            'quantity'       => $this->numeric($data['quantity'] ?? null, 'quantity', $row),
            'unit_price'     => isset($data['unit_price'])  ? $this->numericOrNull($data['unit_price'])  : null,
            'total_amount'   => isset($data['total_amount']) ? $this->numericOrNull($data['total_amount']) : null,
            'transaction_id' => ($t = trim((string) ($data['transaction_id'] ?? ''))) !== '' ? $t : null,
            'line_no'        => $this->lineNo($data['line_no'] ?? null, $row),
            'discount'       => isset($data['discount']) ? $this->numericOrNull($data['discount']) : null,
            'payment_method' => $this->optText($data, 'payment_method'),
            // WP3.4 — hardening fields (an unusable optional value → NULL + DQ warning).
            'channel'        => $this->optText($data, 'channel'),
            'cost_amount'    => $this->optNumber($data, 'cost_amount'),
            'currency'       => $this->optCurrency($data, 'currency'),
            'customer_ref'   => $this->optText($data, 'customer_ref'),
            'promotion_ref'  => $this->optText($data, 'promotion_ref'),
        ];

        // WP3.1: content fingerprint (receipt line excluded), for cross-import
        // duplicate checks on rows that carry no receipt id.
        $attrs['row_hash'] = hash('sha256', implode('|', [
            $attrs['tenant_id'], $attrs['date'], mb_strtolower($attrs['sku']), mb_strtolower(trim((string) $attrs['location'])),
            $attrs['quantity'], $attrs['unit_price'], $attrs['total_amount'], $attrs['transaction_id'],
        ]));

        if ($location) {
            $attrs['store_id'] = $this->resolveStore($import->tenant_id, $location);
        }

        if ($productId = $this->resolveProductId($import->tenant_id, $attrs['sku'])) {
            $attrs['product_id'] = $productId;
        }

        return $attrs;
    }

    private function writeInventoryLevel(Import $import, array $data, int $row): void
    {
        // WP3.5: same natural-key semantics as the bulk path.
        $now = now();
        $attrs = array_merge($this->insertTemplate('inventory_levels'), $this->buildInventoryLevelAttrs($import, $data, $row), ['created_at' => $now, 'updated_at' => $now]);
        $this->lastWriteWasDuplicate = $this->writeBatch('inventory_levels', [$attrs]) > 0;
    }

    private function buildInventoryLevelAttrs(Import $import, array $data, int $row): array
    {
        // Only the SKU is truly required. A blank stock quantity means 0 (out of
        // stock) — CSV exports routinely write 0 as an empty cell — so we default
        // it rather than rejecting the row.
        $this->requireFields($data, ['sku'], $row);

        $location = $data['location'] ?? null;

        $attrs = [
            'tenant_id'     => $import->tenant_id,
            'import_id'     => $import->id,
            'sku'           => $this->str($data['sku'], 'sku', $row),
            'location'      => $location,
            'on_hand_qty'   => $this->numericOrZero($data['on_hand_qty'] ?? null),
            'reorder_point'   => isset($data['reorder_point']) ? $this->numericOrNull($data['reorder_point']) : null,
            // WP3.5: a snapshot without a date is the import day's snapshot (it is part of the natural key).
            'as_of_date'      => (isset($data['as_of_date']) ? $this->parseDateOrNull($data['as_of_date'], $row) : null)
                ?? ($import->created_at ?? now())->toDateString(),
            'on_order_qty'    => isset($data['on_order_qty']) ? $this->numericOrNull($data['on_order_qty']) : null,
            'inventory_value' => isset($data['inventory_value']) ? $this->numericOrNull($data['inventory_value']) : null,
            // WP3.4 — hardening fields.
            'safety_stock'    => $this->optNumber($data, 'safety_stock'),
            'allocated_qty'   => $this->optNumber($data, 'allocated_qty'),
            'in_transit_qty'  => $this->optNumber($data, 'in_transit_qty'),
            'unit_cost'       => $this->optNumber($data, 'unit_cost'),
            'batch_ref'       => $this->optText($data, 'batch_ref'),
            'expiry_date'     => isset($data['expiry_date']) ? $this->parseDateOrNull($data['expiry_date'], $row) : null,
        ];

        if ($location) {
            $attrs['store_id'] = $this->resolveStore($import->tenant_id, $location);
        }

        if ($productId = $this->resolveProductId($import->tenant_id, $attrs['sku'])) {
            $attrs['product_id'] = $productId;
        }

        return $attrs;
    }

    /**
     * WP3.4 (audit H25): master data is updated PARTIALLY — only columns the
     * file actually carries (mapped and non-blank) are written, so a
     * price-only file no longer wipes category, supplier, cost and barcode.
     */
    private function writeProduct(Import $import, array $data, int $row): void
    {
        $this->requireFields($data, ['sku', 'name'], $row);

        $sku = $this->str($data['sku'], 'sku', $row);
        $attrs = $this->present([
            'name'          => $this->str($data['name'], 'name', $row),
            'category'      => $this->optText($data, 'category'),
            'subcategory'   => $this->optText($data, 'subcategory'),
            'unit_cost'     => $this->optNumber($data, 'unit_cost'),
            'selling_price' => $this->optNumber($data, 'selling_price'),
            'supplier'      => $this->optText($data, 'supplier'),
            'barcode'       => $this->optText($data, 'barcode'),
            'brand'         => $this->optText($data, 'brand'),
            'pack_size'     => $this->optText($data, 'pack_size'),
            'department'    => $this->optText($data, 'department'),
            'uom'           => $this->optText($data, 'uom'),
            'weight_grams'  => $this->optNumber($data, 'weight_grams', 0),
            'tax_rate'      => $this->optNumber($data, 'tax_rate', 0, 100),
            'gtin'          => $this->optText($data, 'gtin', '/^\d{8}$|^\d{12,14}$/'),
            'season'        => $this->optText($data, 'season'),
            'status'        => $this->optText($data, 'status'),
            'length_mm'     => $this->optNumber($data, 'length_mm', 0),
            'width_mm'      => $this->optNumber($data, 'width_mm', 0),
            'height_mm'     => $this->optNumber($data, 'height_mm', 0),
            'volume_cm3'    => $this->optNumber($data, 'volume_cm3', 0),
        ]);

        $product = Product::firstOrNew(['tenant_id' => $import->tenant_id, 'sku' => $sku]);
        $product->fill($attrs);

        // Volume follows the dimensions when the file doesn't give it.
        if (! array_key_exists('volume_cm3', $attrs) && $product->length_mm && $product->width_mm && $product->height_mm) {
            $product->volume_cm3 = round((float) $product->length_mm * (float) $product->width_mm * (float) $product->height_mm / 1000, 2);
        }

        $product->save();
    }

    private function writePurchaseOrder(Import $import, array $data, int $row): void
    {
        // WP3.5: same natural-key semantics as the bulk path.
        $now = now();
        $attrs = array_merge($this->insertTemplate('purchase_orders'), $this->buildPurchaseOrderAttrs($import, $data, $row), ['created_at' => $now, 'updated_at' => $now]);
        $this->lastWriteWasDuplicate = $this->writeBatch('purchase_orders', [$attrs]) > 0;
    }

    private function buildPurchaseOrderAttrs(Import $import, array $data, int $row): array
    {
        $this->requireFields($data, ['po_number', 'supplier', 'sku', 'qty_ordered', 'order_date'], $row);

        $attrs = [
            'tenant_id'     => $import->tenant_id,
            'import_id'     => $import->id,
            'po_number'     => $this->str($data['po_number'], 'po_number', $row),
            'supplier'      => $this->str($data['supplier'], 'supplier', $row),
            'sku'           => $this->str($data['sku'], 'sku', $row),
            'qty_ordered'   => $this->numeric($data['qty_ordered'], 'qty_ordered', $row),
            'qty_received'  => isset($data['qty_received'])  ? $this->numericOrNull($data['qty_received'])  : null,
            'unit_cost'     => isset($data['unit_cost'])     ? $this->numericOrNull($data['unit_cost'])     : null,
            'order_date'    => $this->parseDate($data['order_date'], $row),
            'expected_date' => isset($data['expected_date']) ? $this->parseDateOrNull($data['expected_date'], $row) : null,
            'received_date' => isset($data['received_date']) ? $this->parseDateOrNull($data['received_date'], $row) : null,
            'location'      => $data['location'] ?? null,
            'open_qty'      => isset($data['open_qty'])  ? $this->numericOrNull($data['open_qty'])  : null,
            'late_days'     => isset($data['late_days']) ? (int) $this->numericOrNull($data['late_days']) : null,
            'fill_rate'     => isset($data['fill_rate']) ? $this->numericOrNull($data['fill_rate']) : null,
            // WP3.4 — hardening fields.
            'currency'      => $this->optCurrency($data, 'currency'),
            'status'        => $this->optText($data, 'status'),
            'buyer'         => $this->optText($data, 'buyer'),
        ];

        if (! ValueParser::isBlank($data['location'] ?? null)) {
            $attrs['store_id'] = $this->resolveStore($import->tenant_id, $data['location']);
        }

        if ($productId = $this->resolveProductId($import->tenant_id, $attrs['sku'])) {
            $attrs['product_id'] = $productId;
        }

        // Link (or create) the supplier master so supplier-level rules and
        // reporting work off a real supplier record, not just a text name.
        $attrs['supplier_id'] = $this->resolveSupplier($import->tenant_id, $attrs['supplier']);

        return $attrs;
    }

    private function writeStore(Import $import, array $data, int $row): void
    {
        $this->requireFields($data, ['name'], $row);

        $name = $this->str($data['name'], 'name', $row);
        $code = $this->optText($data, 'code');
        $attrs = $this->present([
            'name'           => $name,
            'code'           => $code,
            'address'        => $this->optText($data, 'address'),
            'city'           => $this->optText($data, 'city'),
            'region'         => $this->optText($data, 'region'),
            'country'        => $this->optText($data, 'country'),
            'format'         => $this->optText($data, 'format'),
            'postal_code'    => $this->optText($data, 'postal_code'),
            'latitude'       => $this->optNumber($data, 'latitude', -90, 90),
            'longitude'      => $this->optNumber($data, 'longitude', -180, 180),
            'phone'          => $this->optText($data, 'phone'),
            'email'          => $this->optEmail($data, 'email'),
            'timezone'       => $this->optTimezone($data, 'timezone'),
            'currency'       => $this->optCurrency($data, 'currency'),
            'banner'         => $this->optText($data, 'banner'),
            'status'         => $this->optText($data, 'status'),
            'opened_on'      => isset($data['opened_on']) ? $this->parseDateOrNull($data['opened_on'], $row) : null,
            'sales_area_sqm' => $this->optNumber($data, 'sales_area_sqm', 0),
        ]);

        // WP3.4: enrich the store the facts already point at — by code, then by
        // name (case/space-insensitive), then a store auto-created from a sales
        // file that identified it by this code — instead of duplicating it.
        $tenantId = (int) $import->tenant_id;
        $store = ($code !== null ? $this->storeByNormalised($tenantId, 'code', $code) : null)
            ?? $this->storeByNormalised($tenantId, 'name', $name)
            ?? ($code !== null ? Store::where('tenant_id', $tenantId)->whereNull('code')
                ->whereRaw("lower(regexp_replace(trim(name), '\\s+', ' ', 'g')) = ?", [self::norm($code)])->first() : null);

        $store ??= new Store(['tenant_id' => $tenantId]);
        $store->fill($attrs)->save();

        $this->rememberStore($tenantId, $store);
    }

    private function writeSupplier(Import $import, array $data, int $row): void
    {
        $this->requireFields($data, ['name'], $row);

        $name = $this->str($data['name'], 'name', $row);
        $attrs = $this->present([
            'name'            => $name,
            'code'            => $this->optText($data, 'code'),
            'lead_time_days'  => ($lt = $this->optNumber($data, 'lead_time_days', 0)) === null ? null : (int) round($lt),
            'contact_email'   => $this->optEmail($data, 'contact_email'),
            'contact_phone'   => $this->optText($data, 'contact_phone'),
            'type'            => $this->optText($data, 'type'),
            'specialization'  => $this->optText($data, 'specialization'),
            'country'         => $this->optText($data, 'country'),
            'region'          => $this->optText($data, 'region'),
            'city'            => $this->optText($data, 'city'),
            'currency'        => $this->optCurrency($data, 'currency'),
            'payment_terms'   => $this->optText($data, 'payment_terms'),
            'min_order_value' => $this->optNumber($data, 'min_order_value', 0),
            'website'         => $this->optText($data, 'website'),
            'status'          => $this->optText($data, 'status'),
        ]);

        $supplier = Supplier::where('tenant_id', $import->tenant_id)
            ->whereRaw('lower(trim(name)) = ?', [mb_strtolower(trim($name))])
            ->first() ?? new Supplier(['tenant_id' => $import->tenant_id]);
        $supplier->fill($attrs)->save();
    }

    private function writeReturn(Import $import, array $data, int $row): void
    {
        // WP3.5: same natural-key semantics as the bulk path.
        $now = now();
        $attrs = array_merge($this->insertTemplate('sales_returns'), $this->buildReturnAttrs($import, $data, $row), ['created_at' => $now, 'updated_at' => $now]);
        $this->lastWriteWasDuplicate = $this->writeBatch('sales_returns', [$attrs]) > 0;
    }

    /** W11: a waste / write-off line (natural key: day, SKU, store, reason, document). */
    private function writeWaste(Import $import, array $data, int $row): void
    {
        $now = now();
        $attrs = array_merge($this->insertTemplate('waste_events'), $this->buildWasteAttrs($import, $data, $row), ['created_at' => $now, 'updated_at' => $now]);
        $this->lastWriteWasDuplicate = $this->writeBatch('waste_events', [$attrs]) > 0;
    }

    private function buildWasteAttrs(Import $import, array $data, int $row): array
    {
        $this->requireFields($data, ['date', 'sku', 'quantity'], $row);
        $location = $data['location'] ?? null;
        // A write-off is often exported as a negative stock adjustment: the size is what counts.
        $qty = abs((float) $this->numeric($data['quantity'], 'quantity', $row));
        $value = $this->optNumber($data, 'value');
        $reason = $this->optText($data, 'reason');

        $attrs = [
            'tenant_id' => $import->tenant_id,
            'import_id' => $import->id,
            'date'      => $this->parseDate($data['date'], $row),
            'sku'       => $this->str($data['sku'], 'sku', $row),
            'location'  => $location,
            'quantity'  => $qty,
            'value'     => $value !== null ? abs($value) : null,
            'reason'    => $reason !== null ? mb_strtolower(mb_substr($reason, 0, 60)) : null,
            'waste_ref' => ($ref = $this->optText($data, 'waste_ref')) !== null ? mb_substr($ref, 0, 100) : null,
        ];
        if ($location) {
            $attrs['store_id'] = $this->resolveStore($import->tenant_id, $location);
        }
        if ($productId = $this->resolveProductId($import->tenant_id, $attrs['sku'])) {
            $attrs['product_id'] = $productId;
        }

        return $attrs;
    }

    /** W10: a promotion-calendar row (natural key: promotion, SKU, store). */
    private function writePromotion(Import $import, array $data, int $row): void
    {
        $now = now();
        $attrs = array_merge($this->insertTemplate('promotions'), $this->buildPromotionAttrs($import, $data, $row), ['created_at' => $now, 'updated_at' => $now]);
        $this->lastWriteWasDuplicate = $this->writeBatch('promotions', [$attrs]) > 0;
    }

    private function buildPromotionAttrs(Import $import, array $data, int $row): array
    {
        $this->requireFields($data, ['promotion_ref', 'sku', 'start_date', 'end_date'], $row);
        $from = $this->parseDate($data['start_date'], $row);
        $to   = $this->parseDate($data['end_date'], $row);
        if ($to < $from) {
            throw new \InvalidArgumentException("Row {$row}: the promotion ends ({$to}) before it starts ({$from}).");
        }
        $location = $data['location'] ?? null;

        $attrs = [
            'tenant_id'     => $import->tenant_id,
            'import_id'     => $import->id,
            'promotion_ref' => $this->str($data['promotion_ref'], 'promotion_ref', $row),
            'name'          => $this->optText($data, 'promotion_name'),
            'sku'           => $this->str($data['sku'], 'sku', $row),
            'location'      => $location,
            'starts_on'     => $from,
            'ends_on'       => $to,
            'mechanic'      => $this->optText($data, 'mechanic'),
            'discount_pct'  => $this->optNumber($data, 'discount_pct', 0, 100),
            'promo_price'   => $this->optNumber($data, 'promo_price', 0),
        ];
        if ($location) {
            $attrs['store_id'] = $this->resolveStore($import->tenant_id, $location);
        }
        if ($productId = $this->resolveProductId($import->tenant_id, $attrs['sku'])) {
            $attrs['product_id'] = $productId;
        }

        return $attrs;
    }

    private function buildReturnAttrs(Import $import, array $data, int $row): array
    {
        $this->requireFields($data, ['date', 'sku', 'quantity'], $row);

        $location = $data['location'] ?? null;

        $attrs = [
            'tenant_id' => $import->tenant_id,
            'import_id' => $import->id,
            'date'      => $this->parseDate($data['date'], $row),
            'sku'       => $this->str($data['sku'], 'sku', $row),
            'location'  => $location,
            'quantity'  => $this->numeric($data['quantity'], 'quantity', $row),
            'value'     => isset($data['value']) ? $this->numericOrNull($data['value']) : null,
            'reason'    => $this->optText($data, 'reason'),
            'return_id' => $this->optText($data, 'return_id'),
            // WP3.4 — hardening fields.
            'channel'   => $this->optText($data, 'channel'),
            'condition' => $this->optText($data, 'condition'),
            'original_transaction_ref' => $this->optText($data, 'original_transaction_ref'),
        ];

        if ($location) {
            $attrs['store_id'] = $this->resolveStore($import->tenant_id, $location);
        }

        if ($productId = $this->resolveProductId($import->tenant_id, $attrs['sku'])) {
            $attrs['product_id'] = $productId;
        }

        return $attrs;
    }

    private function writeUser(Import $import, array $data, int $row): void
    {
        $this->requireFields($data, ['name', 'email'], $row);

        $email = strtolower(trim($this->str($data['email'], 'email', $row)));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Row {$row}: '{$email}' is not a valid email address.");
        }

        $existing = User::where('email', $email)->first();

        $attrs = [
            'tenant_id' => $import->tenant_id,
            'name'      => $this->str($data['name'], 'name', $row),
        ];

        // WP2.3 (audit): an import only changes admin rights when a role column
        // was actually mapped AND the person who uploaded it may manage users.
        // (Before: a file without a role column demoted every listed admin, and
        // an API key with write:ingest could grant admin rights.)
        $roleMapped = array_key_exists('role', $data);
        $actor = $import->user_id ? User::find($import->user_id) : null;
        if ($roleMapped && $actor !== null && $actor->canManageUsers()) {
            $role = strtolower(trim((string) ($data['role'] ?? '')));
            $attrs['is_tenant_admin'] = in_array($role, ['admin', 'tenant_admin', 'tenant admin', 'administrator', 'manager'], true);
        } elseif (! $existing) {
            $attrs['is_tenant_admin'] = false;
        }

        if ($existing) {
            // Never let an import touch a platform administrator or move another tenant's user.
            if ((int) $existing->tenant_id !== (int) $import->tenant_id) {
                throw new \InvalidArgumentException("Row {$row}: user '{$email}' already exists under a different tenant.");
            }
            if ($existing->is_super_admin || $existing->isOwner()) {
                throw new \InvalidArgumentException("Row {$row}: '{$email}' is a platform administrator and cannot be changed by an import.");
            }
            $existing->update($attrs);
            $user = $existing;
        } else {
            // Random password — imported users must have one set by an admin
            // (no self-service reset until MAIL_* is configured).
            $attrs['email']    = $email;
            $attrs['password'] = Str::random(40);
            $user = User::create($attrs);
        }

        // W12: the store(s) this person runs, when the column is mapped (a blank cell unlinks).
        if (array_key_exists('stores', $data)) {
            $this->syncUserStores($import, $user, (string) ($data['stores'] ?? ''), $row);
        }
    }

    /** W12: link a user to stores by code or name; an unknown store is a warning, never a guess. */
    private function syncUserStores(Import $import, User $user, string $value, int $row): void
    {
        $wanted = array_values(array_filter(array_map('trim', preg_split('/[;,|]/', $value) ?: [])));
        $ids = [];
        foreach ($wanted as $w) {
            $id = \App\Models\Store::where('tenant_id', $import->tenant_id)
                ->where(fn ($q) => $q->whereRaw('lower(code) = ?', [mb_strtolower($w)])->orWhereRaw('lower(name) = ?', [mb_strtolower($w)]))
                ->value('id');
            if ($id) {
                $ids[(int) $id] = ['tenant_id' => $import->tenant_id];
            } else {
                $this->warnInvalid();
            }
        }
        $user->stores()->sync($ids);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function applyMap($columnMap, array $rawRow): array
    {
        $data = [];
        foreach ($rawRow as $sourceHeader => $value) {
            /** @var ImportColumnMap|null $map */
            $map = $columnMap->get($sourceHeader);
            if ($map && $map->target_field) {
                $value = $value !== '' ? $value : null;
                // WP3.4: "Weight (kg)" / "Length_cm" — a unit in the header applies
                // to plain numbers in the column.
                if ($value !== null && isset(self::UNIT_FIELDS[$map->target_field]) && preg_match('/^[\s\d.,\-+]+$/', (string) $value)) {
                    $unit = self::headerUnit((string) $sourceHeader, self::UNIT_FIELDS[$map->target_field]);
                    if ($unit !== null) {
                        $value = trim((string) $value) . ' ' . $unit;
                    }
                }
                $data[$map->target_field] = $value;
            }
        }
        return $data;
    }

    /** WP3.4: fields whose unit may be given in the column header. */
    private const UNIT_FIELDS = [
        'weight_grams' => ValueParser::WEIGHT_UNITS,
        'length_mm'    => ValueParser::LENGTH_UNITS,
        'width_mm'     => ValueParser::LENGTH_UNITS,
        'height_mm'    => ValueParser::LENGTH_UNITS,
        'volume_cm3'   => ValueParser::VOLUME_UNITS,
    ];

    private static function headerUnit(string $header, array $units): ?string
    {
        $tokens = preg_split('/[^a-z0-9³]+/u', mb_strtolower($header)) ?: [];
        foreach (array_reverse($tokens) as $t) {
            if ($t !== '' && array_key_exists($t, $units)) {
                return $t;
            }
        }

        return null;
    }

    /** WP3.2: the CSV dialect detected at upload (null for spreadsheets / legacy imports → re-detected). */
    private function dialectOf(Import $import): array
    {
        return ['delimiter' => $import->delimiter, 'encoding' => $import->encoding];
    }

    /** WP3.1: a receipt line number is a positive whole number, or absent. */
    private function lineNo(mixed $value, int $row): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^\d+(\.0+)?$/', $value) || (int) $value < 1) {
            throw new \InvalidArgumentException("Row {$row}: line number '{$value}' must be a whole number of 1 or more.");
        }

        return (int) $value;
    }

    private function requireFields(array $data, array $fields, int $row): void
    {
        foreach ($fields as $field) {
            // WP3.2 (audit H26): blank means blank — "0" is a value.
            if (ValueParser::isBlank($data[$field] ?? null)) {
                throw new \InvalidArgumentException("Row {$row}: required field '{$field}' is missing or empty.");
            }
        }
    }

    // ── WP3.4: optional values — unusable → NULL + data-quality warning ──────

    /** Only the attributes the file actually carries (mapped and non-blank). */
    private function present(array $attrs): array
    {
        return array_filter($attrs, fn ($v) => $v !== null);
    }

    private function warnInvalid(): void
    {
        $this->firewall()?->warn(\App\Services\DataQuality\Reasons::WARN_INVALID_VALUE);
    }

    private function optText(array $data, string $field, ?string $pattern = null, int $max = 255): ?string
    {
        $v = $data[$field] ?? null;
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if ($pattern !== null && ! preg_match($pattern, $v)) {
            $this->warnInvalid();

            return null;
        }
        if (mb_strlen($v) > $max) {
            $this->warnInvalid();

            return mb_substr($v, 0, $max);
        }

        return $v;
    }

    private function optNumber(array $data, string $field, ?float $min = null, ?float $max = null): ?float
    {
        $v = $data[$field] ?? null;
        if ($v === null) {
            return null;
        }
        $n = $v instanceof InvalidValue ? null : $this->canonical()->number($v);
        if ($n === null || ($min !== null && (float) $n < $min) || ($max !== null && (float) $n > $max)) {
            $this->warnInvalid();

            return null;
        }

        return (float) $n;
    }

    private function optCurrency(array $data, string $field): ?string
    {
        $v = $this->optText($data, $field);
        if ($v === null) {
            return null;
        }
        $v = mb_strtoupper($v);
        if (! preg_match('/^[A-Z]{3}$/', $v)) {
            $this->warnInvalid();

            return null;
        }

        return $v;
    }

    private function optEmail(array $data, string $field): ?string
    {
        $v = $this->optText($data, $field);
        if ($v !== null && ! filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->warnInvalid();

            return null;
        }

        return $v === null ? null : mb_strtolower($v);
    }

    private function optTimezone(array $data, string $field): ?string
    {
        $v = $this->optText($data, $field);
        if ($v !== null && ! in_array($v, \DateTimeZone::listIdentifiers(), true)) {
            $this->warnInvalid();

            return null;
        }

        return $v;
    }

    /** Case- and space-insensitive key for store / supplier names and codes. */
    private static function norm(?string $v): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $v) ?? ''));
    }

    private function storeByNormalised(int $tenantId, string $column, string $value): ?Store
    {
        return Store::where('tenant_id', $tenantId)
            ->whereRaw("lower(regexp_replace(trim({$column}), '\\s+', ' ', 'g')) = ?", [self::norm($value)])
            ->orderBy('id')
            ->first();
    }

    private function rememberStore(int $tenantId, Store $store): void
    {
        if ($this->storeCache !== null && $this->cachedTenantId === $tenantId) {
            $this->storeCache[self::norm($store->name)] = (int) $store->id;
            if ($store->code) {
                $this->storeCache[self::norm($store->code)] ??= (int) $store->id;
            }
        }
    }

    /** WP3.2: the import's date/number rules (import → tenant → default). */
    private function parser(): ValueParser
    {
        return $this->parser ??= new ValueParser();
    }

    /**
     * WP3.2: make a row ready for the writers — normalised once (older failed
     * rows stored before WP3.2 are normalised here), with every unreadable
     * date / number replaced by an InvalidValue so no later step re-reads it
     * under different rules.
     */
    private function prepareForWrite(Import $import, array $data): array
    {
        $data = $this->parser()->normalizeRow($import->data_type, $data);
        foreach ($data[ValueParser::META_INVALID] ?? [] as $field => $reason) {
            if (array_key_exists($field, $data) && ! $data[$field] instanceof InvalidValue) {
                $data[$field] = new InvalidValue((string) $data[$field], (string) $reason);
            }
        }

        return ValueParser::stripMeta($data);
    }

    /** Values reaching the writers are canonical (ISO dates, dot decimals). */
    private function canonical(): ValueParser
    {
        return $this->canonicalParser ??= ValueParser::canonical();
    }

    private function parseDate(mixed $value, int $row): string
    {
        if ($value instanceof InvalidValue) {
            if ($value->reason === ValueParser::AMBIGUOUS) {
                throw new \InvalidArgumentException("Row {$row}: date '{$value->raw}' could be day/month or month/day — set the date format for this file.");
            }
            throw new \InvalidArgumentException("Row {$row}: cannot read '{$value->raw}' as a date (expected {$this->parser()->dateFormatLabel()}).");
        }
        $iso = $this->canonical()->date($value)['value'];
        if ($iso === null) {
            throw new \InvalidArgumentException("Row {$row}: cannot read '{$value}' as a date (expected {$this->parser()->dateFormatLabel()}).");
        }

        return $iso;
    }

    /**
     * Optional date: blank or unreadable → null, but an AMBIGUOUS value is never
     * guessed — it fails the row like a required one.
     */
    private function parseDateOrNull(mixed $value, int $row = 0): ?string
    {
        if ($value instanceof InvalidValue) {
            if ($value->reason === ValueParser::AMBIGUOUS) {
                return $this->parseDate($value, $row);
            }
            $this->warnInvalid();

            return null;
        }

        return $this->canonical()->date($value)['value'];
    }

    private function numeric(mixed $value, string $field, int $row): float
    {
        $n = $value instanceof InvalidValue ? null : $this->canonical()->number($value);
        if ($n === null) {
            throw new \InvalidArgumentException("Row {$row}: '{$field}' value '{$value}' is not a valid number.");
        }

        return (float) $n;
    }

    private function numericOrNull(mixed $value): ?float
    {
        if ($value instanceof InvalidValue) {
            $this->warnInvalid();
        }
        $n = $value instanceof InvalidValue ? null : $this->canonical()->number($value);

        return $n === null ? null : (float) $n;
    }

    /**
     * Numeric value where a blank/missing cell means 0 (e.g. a stock quantity
     * written as an empty cell by a CSV export). Non-numeric junk also falls
     * back to 0 rather than rejecting the row.
     */
    private function numericOrZero(mixed $value): float
    {
        return (float) (($value instanceof InvalidValue ? null : $this->canonical()->number($value)) ?? 0);
    }

    private function str(?string $value, string $field, int $row): string
    {
        $trimmed = trim($value ?? '');
        if ($trimmed === '') {
            throw new \InvalidArgumentException("Row {$row}: required field '{$field}' is empty.");
        }
        return $trimmed;
    }

    /**
     * Resolve (or create) a Store by name for the given tenant.
     * Returns the store's primary key.
     */
    private function resolveStore(int $tenantId, string $locationName): int
    {
        $name = trim(preg_replace('/\s+/u', ' ', $locationName) ?? $locationName);
        $key  = self::norm($name);

        if ($this->storeCache !== null && $this->cachedTenantId === $tenantId && isset($this->storeCache[$key])) {
            return $this->storeCache[$key];
        }

        // Match an existing store by NAME *or* CODE — case- and space-insensitive
        // (WP3.4) — so "ST042", "st042 " and "Fujairah Grocery 42" all link to the
        // master record instead of creating duplicates. Only create when nothing matches.
        $store = $this->storeByNormalised($tenantId, 'name', $name)
            ?? $this->storeByNormalised($tenantId, 'code', $name)
            ?? Store::create(['tenant_id' => $tenantId, 'name' => $name]);

        if ($this->storeCache !== null && $this->cachedTenantId === $tenantId) {
            $this->storeCache[$key] = (int) $store->id;
        }

        return $store->id;
    }

    /**
     * Resolve (or create) a Supplier by name for the given tenant.
     * Returns the supplier's primary key, or null for a blank name.
     */
    private function resolveSupplier(int $tenantId, ?string $supplierName): ?int
    {
        $name = trim((string) $supplierName);
        if ($name === '') {
            return null;
        }

        $key = self::norm($name);
        if ($this->supplierCache !== null && $this->cachedTenantId === $tenantId && isset($this->supplierCache[$key])) {
            return $this->supplierCache[$key];
        }

        $find = fn () => Supplier::where('tenant_id', $tenantId)->whereRaw('lower(trim(name)) = ?', [mb_strtolower($name)])->orderBy('id')->first();
        try {
            // In its own savepoint, so a lost race doesn't abort the surrounding chunk.
            $supplier = $find() ?? DB::transaction(fn () => Supplier::create(['tenant_id' => $tenantId, 'name' => $name]));
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // WP6.5: (tenant, lower(name)) is unique — a concurrent import created it first.
            $supplier = $find() ?? throw $e;
        }

        if ($this->supplierCache !== null && $this->cachedTenantId === $tenantId) {
            $this->supplierCache[$key] = (int) $supplier->id;
        }

        return $supplier->id;
    }

    /**
     * Record an IngestionRun so the Data Health Center gets real ingestion
     * metadata (status, counts, validation score, rejected sample) for this run.
     * Users are an account-setup import, not a health-tracked dataset — skipped.
     */
    private function recordIngestionRun(Import $import, int $total, int $imported, int $failed, ?Carbon $startedAt): void
    {
        $map = [
            Import::TYPE_SALES           => IngestionRun::TYPE_SALES,
            Import::TYPE_INVENTORY       => IngestionRun::TYPE_INVENTORY,
            Import::TYPE_PRODUCTS        => IngestionRun::TYPE_PRODUCTS,
            Import::TYPE_PURCHASE_ORDERS => IngestionRun::TYPE_PURCHASE_ORDERS,
            Import::TYPE_STORES          => IngestionRun::TYPE_STORES,
            Import::TYPE_SUPPLIERS       => IngestionRun::TYPE_SUPPLIERS,
        ];

        $dataType = $map[$import->data_type] ?? null;
        if ($dataType === null) {
            return; // e.g. users — not a health-tracked dataset
        }

        $status = $failed === 0
            ? IngestionRun::STATUS_COMPLETED
            : ($imported > 0 ? IngestionRun::STATUS_PARTIAL : IngestionRun::STATUS_FAILED);

        $sample = ImportRow::where('import_id', $import->id)
            ->limit(20)
            ->get(['row_number', 'error_message'])
            ->map(fn ($r) => ['row' => $r->row_number, 'error' => $r->error_message])
            ->all();

        try {
            IngestionRun::create([
                'tenant_id'        => $import->tenant_id,
                'data_type'        => $dataType,
                'source'           => 'csv',
                'status'           => $status,
                'filename'         => $import->original_filename,
                'rows_processed'   => $total,
                'rows_imported'    => $imported,
                'rows_failed'      => $failed,
                'validation_score' => $total > 0 ? round(($imported / $total) * 100, 2) : 100,
                'rejected_sample'  => $sample,
                'started_at'       => $startedAt ?? $import->created_at,
                'completed_at'     => now(),
                'import_id'        => $import->id,
            ]);
        } catch (\Throwable $e) {
            // Ingestion-run recording is best-effort — never fail the import over it.
            Log::error('Failed to record IngestionRun', ['import_id' => $import->id, 'error' => $e->getMessage()]);
        }
    }
}
