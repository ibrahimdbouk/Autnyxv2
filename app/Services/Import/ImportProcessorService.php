<?php

namespace App\Services\Import;

use App\Models\IngestionRun;
use App\Models\Import;
use App\Models\ImportColumnMap;
use App\Models\ImportRow;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesReturn;
use App\Models\SalesTransaction;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Anomaly\AnomalyDetectionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

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

        $this->storeCache = Store::where('tenant_id', $tenantId)
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [trim((string) $name) => (int) $id])
            ->all();

        $this->supplierCache = Supplier::where('tenant_id', $tenantId)
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [trim((string) $name) => (int) $id])
            ->all();

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

        foreach ($rows as $importRow) {
            $mappedData = $importRow->mapped_data ?? [];

            try {
                DB::beginTransaction();
                $this->writeMappedData($import, $mappedData, $importRow->row_number);
                DB::commit();
                $importRow->delete();
                $retried++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $importRow->update(['error_message' => $e->getMessage()]);
                $stillFailed++;
            }
        }

        // Recompute failed_rows count
        $remaining = ImportRow::where('import_id', $import->id)->count();
        $status = $remaining === 0 ? Import::STATUS_COMPLETED : Import::STATUS_COMPLETED_WITH_ERRORS;
        $import->update([
            'failed_rows' => $remaining,
            'imported_rows' => $import->imported_rows + $retried,
            'status' => $status,
        ]);

        return ['retried' => $retried, 'still_failed' => $stillFailed];
    }

    /**
     * Mark an import stuck in "importing" for more than $minutes as failed.
     * Call from a scheduled command or health check.
     */
    public static function recoverStuckImports(int $minutes = 10): int
    {
        return Import::where('status', Import::STATUS_IMPORTING)
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
            default                      => null,
        };

        if ($modelClass === null) {
            return 0;
        }

        $deleted = $modelClass::where('tenant_id', $import->tenant_id)
            ->where('import_id', $import->id)
            ->delete();

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

    public function process(Import $import): void
    {
        $import->update(['status' => Import::STATUS_IMPORTING]);
        $startedAt = now();

        try {
            $columnMap = $import->columnMaps()
                ->where('is_skipped', false)
                ->whereNotNull('target_field')
                ->get()
                ->keyBy('source_header');

            $filePath = Storage::disk($import->disk)->path($import->path);
            $allRows  = $this->readAllRows($filePath);

            $total    = count($allRows);
            $imported = 0;
            $failed   = 0;

            foreach ($allRows as $rowNumber => $rawRow) {
                try {
                    DB::beginTransaction();
                    $this->writeRow($import, $columnMap, $rawRow, $rowNumber + 2); // +2 = 1-indexed + skip header
                    DB::commit();
                    $imported++;
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $failed++;
                    ImportRow::create([
                        'import_id'     => $import->id,
                        'tenant_id'     => $import->tenant_id,
                        'row_number'    => $rowNumber + 2,
                        'raw_data'      => $rawRow,
                        'mapped_data'   => $this->applyMap($columnMap, $rawRow),
                        'error_message' => $e->getMessage(),
                        'status'        => ImportRow::STATUS_PENDING,
                    ]);
                }
            }

            $status = $failed > 0 ? Import::STATUS_COMPLETED_WITH_ERRORS : Import::STATUS_COMPLETED;

            $import->update([
                'status'        => $status,
                'total_rows'    => $total,
                'imported_rows' => $imported,
                'failed_rows'   => $failed,
            ]);

            // Record an IngestionRun so Data Health sees this ingestion.
            $this->recordIngestionRun($import, $total, $imported, $failed, $startedAt);

            // Run anomaly detection after every completed import
            try {
                app(AnomalyDetectionService::class)->runForTenant($import->tenant_id);
            } catch (\Throwable $e) {
                Log::error('Anomaly detection failed after import', ['import_id' => $import->id, 'error' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            Log::error('Import processing failed', ['import_id' => $import->id, 'error' => $e->getMessage()]);
            $import->update(['status' => Import::STATUS_FAILED, 'error_message' => $e->getMessage()]);
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

        $import->update([
            'status'         => Import::STATUS_IMPORTING,
            'imported_rows'  => 0,
            'failed_rows'    => 0,
            'process_cursor' => 0,
            'error_message'  => null,
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

        $columnMap = $import->columnMaps()
            ->where('is_skipped', false)
            ->whereNotNull('target_field')
            ->get()
            ->keyBy('source_header');

        $offset   = (int) $import->process_cursor;
        $filePath = Storage::disk($import->disk)->path($import->path);

        try {
            /** @var FileReaderService $reader */
            $reader = app(FileReaderService::class);
            $chunk  = $reader->readRange($filePath, $offset, $chunkSize);
        } catch (\Throwable $e) {
            Log::error('Import chunk read failed', ['import_id' => $import->id, 'error' => $e->getMessage()]);
            $import->update(['status' => Import::STATUS_FAILED, 'error_message' => $e->getMessage()]);

            return ['done' => true, 'processed' => $offset, 'total' => (int) $import->total_rows, 'failed' => true];
        }

        $imported  = 0;
        $failed    = 0;
        $rowNumber = $offset + 2; // 1-index + header row

        $table    = $this->insertTableFor($import->data_type);
        $template = $table ? $this->insertTemplate($table) : [];
        $now      = now();
        $batch    = [];

        foreach ($chunk['rows'] as $rawRow) {
            $data = $this->applyMap($columnMap, $rawRow);
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
                    'raw_data'      => $rawRow,
                    'mapped_data'   => $data,
                    'error_message' => $e->getMessage(),
                    'status'        => ImportRow::STATUS_PENDING,
                ]);
            }
            $rowNumber++;
        }

        // One multi-row INSERT per sub-batch — the core speedup for large files.
        if ($table !== null && ! empty($batch)) {
            foreach (array_chunk($batch, self::INSERT_BATCH) as $slice) {
                try {
                    DB::table($table)->insert(array_column($slice, 'attrs'));
                } catch (\Throwable $e) {
                    // A single bad value aborts the whole multi-row INSERT. Fall
                    // back to per-row so the good rows still land and the bad ones
                    // are captured in the failed-row ledger instead of vanishing.
                    foreach ($slice as $entry) {
                        try {
                            DB::table($table)->insert($entry['attrs']);
                        } catch (\Throwable $rowError) {
                            $imported--;
                            $failed++;
                            ImportRow::create([
                                'import_id'     => $import->id,
                                'tenant_id'     => $import->tenant_id,
                                'row_number'    => $entry['row'],
                                'raw_data'      => $entry['raw'],
                                'mapped_data'   => $entry['mapped'],
                                'error_message' => $rowError->getMessage(),
                                'status'        => ImportRow::STATUS_PENDING,
                            ]);
                        }
                    }
                }
            }
        }

        $newCursor = $offset + (int) $chunk['consumed'];

        $import->update([
            'process_cursor' => $newCursor,
            'imported_rows'  => $import->imported_rows + $imported,
            'failed_rows'    => $import->failed_rows + $failed,
        ]);

        $done = $chunk['eof']
            || (int) $chunk['consumed'] === 0
            || ($import->total_rows > 0 && $newCursor >= $import->total_rows);

        if ($done) {
            $this->finalizeChunkedImport($import);

            return ['done' => true, 'processed' => (int) $import->process_cursor, 'total' => (int) $import->total_rows];
        }

        return ['done' => false, 'processed' => $newCursor, 'total' => (int) $import->total_rows];
    }

    /**
     * Wrap up a chunked import: set the final status, record the ingestion run,
     * and run anomaly detection across the tenant.
     */
    private function finalizeChunkedImport(Import $import): void
    {
        $import->refresh();

        $status = $import->failed_rows > 0
            ? Import::STATUS_COMPLETED_WITH_ERRORS
            : Import::STATUS_COMPLETED;

        $total = max(
            (int) $import->total_rows,
            (int) $import->imported_rows + (int) $import->failed_rows
        );

        $import->update([
            'status'     => $status,
            'total_rows' => $total,
        ]);

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

            // Detection can take minutes on large data, so it runs off the web
            // request as a queued job (requires a queue worker). It populates
            // anomalies + investigations, which the dashboards read.
            \App\Jobs\RunTenantDetectionJob::dispatch($import->tenant_id);
        } catch (\Throwable $e) {
            Log::error('Post-import aggregation/detection dispatch failed', ['import_id' => $import->id, 'error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Write already-mapped data (used by retryRows to avoid re-reading the file).
     */
    private function writeMappedData(Import $import, array $data, int $rowNumber): void
    {
        match ($import->data_type) {
            Import::TYPE_SALES           => $this->writeSalesTransaction($import, $data, $rowNumber),
            Import::TYPE_INVENTORY       => $this->writeInventoryLevel($import, $data, $rowNumber),
            Import::TYPE_PRODUCTS        => $this->writeProduct($import, $data, $rowNumber),
            Import::TYPE_PURCHASE_ORDERS => $this->writePurchaseOrder($import, $data, $rowNumber),
            Import::TYPE_STORES          => $this->writeStore($import, $data, $rowNumber),
            Import::TYPE_SUPPLIERS       => $this->writeSupplier($import, $data, $rowNumber),
            Import::TYPE_USERS           => $this->writeUser($import, $data, $rowNumber),
            Import::TYPE_RETURNS         => $this->writeReturn($import, $data, $rowNumber),
            default                      => throw new \InvalidArgumentException("Unknown data type: {$import->data_type}"),
        };
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
                'transaction_id' => null, 'date' => null, 'sku' => null, 'location' => null,
                'quantity' => null, 'unit_price' => null, 'total_amount' => null, 'discount' => null, 'payment_method' => null,
            ],
            'inventory_levels' => [
                'tenant_id' => null, 'import_id' => null, 'store_id' => null, 'product_id' => null,
                'sku' => null, 'location' => null, 'on_hand_qty' => null, 'reorder_point' => null,
                'as_of_date' => null, 'on_order_qty' => null, 'inventory_value' => null,
            ],
            'purchase_orders' => [
                'tenant_id' => null, 'import_id' => null, 'supplier_id' => null, 'store_id' => null, 'product_id' => null,
                'po_number' => null, 'supplier' => null, 'sku' => null, 'qty_ordered' => null, 'qty_received' => null,
                'unit_cost' => null, 'order_date' => null, 'expected_date' => null, 'received_date' => null,
                'location' => null, 'open_qty' => null, 'late_days' => null, 'fill_rate' => null,
            ],
            'sales_returns' => [
                'tenant_id' => null, 'import_id' => null, 'store_id' => null, 'product_id' => null,
                'return_id' => null, 'date' => null, 'sku' => null, 'location' => null,
                'quantity' => null, 'value' => null, 'reason' => null,
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
        return match ($import->data_type) {
            Import::TYPE_SALES           => $this->buildSalesTransactionAttrs($import, $data, $row),
            Import::TYPE_INVENTORY       => $this->buildInventoryLevelAttrs($import, $data, $row),
            Import::TYPE_PURCHASE_ORDERS => $this->buildPurchaseOrderAttrs($import, $data, $row),
            Import::TYPE_RETURNS         => $this->buildReturnAttrs($import, $data, $row),
            default                      => throw new \InvalidArgumentException("Not a batch-insert type: {$import->data_type}"),
        };
    }

    private function writeRow(Import $import, $columnMap, array $rawRow, int $rowNumber): void
    {
        $data = $this->applyMap($columnMap, $rawRow);

        match ($import->data_type) {
            Import::TYPE_SALES           => $this->writeSalesTransaction($import, $data, $rowNumber),
            Import::TYPE_INVENTORY       => $this->writeInventoryLevel($import, $data, $rowNumber),
            Import::TYPE_PRODUCTS        => $this->writeProduct($import, $data, $rowNumber),
            Import::TYPE_PURCHASE_ORDERS => $this->writePurchaseOrder($import, $data, $rowNumber),
            Import::TYPE_STORES          => $this->writeStore($import, $data, $rowNumber),
            Import::TYPE_SUPPLIERS       => $this->writeSupplier($import, $data, $rowNumber),
            Import::TYPE_USERS           => $this->writeUser($import, $data, $rowNumber),
            Import::TYPE_RETURNS         => $this->writeReturn($import, $data, $rowNumber),
            default                      => throw new \InvalidArgumentException("Unknown data type: {$import->data_type}"),
        };
    }

    private function writeSalesTransaction(Import $import, array $data, int $row): void
    {
        $attrs = $this->buildSalesTransactionAttrs($import, $data, $row);

        $uniqueKey = array_filter(['tenant_id' => $attrs['tenant_id'], 'transaction_id' => $attrs['transaction_id']]);

        if ($attrs['transaction_id'] && SalesTransaction::where($uniqueKey)->exists()) {
            // Upsert by transaction_id
            SalesTransaction::where($uniqueKey)->update($attrs);
        } else {
            SalesTransaction::create($attrs);
        }
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
            'transaction_id' => $data['transaction_id'] ?? null,
            'discount'       => isset($data['discount']) ? $this->numericOrNull($data['discount']) : null,
            'payment_method' => $data['payment_method'] ?? null,
        ];

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
        InventoryLevel::create($this->buildInventoryLevelAttrs($import, $data, $row));
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
            'as_of_date'      => isset($data['as_of_date']) ? $this->parseDateOrNull($data['as_of_date']) : null,
            'on_order_qty'    => isset($data['on_order_qty']) ? $this->numericOrNull($data['on_order_qty']) : null,
            'inventory_value' => isset($data['inventory_value']) ? $this->numericOrNull($data['inventory_value']) : null,
        ];

        if ($location) {
            $attrs['store_id'] = $this->resolveStore($import->tenant_id, $location);
        }

        if ($productId = $this->resolveProductId($import->tenant_id, $attrs['sku'])) {
            $attrs['product_id'] = $productId;
        }

        return $attrs;
    }

    private function writeProduct(Import $import, array $data, int $row): void
    {
        $this->requireFields($data, ['sku', 'name'], $row);

        $attrs = [
            'tenant_id'     => $import->tenant_id,
            'sku'           => $this->str($data['sku'], 'sku', $row),
            'name'          => $this->str($data['name'], 'name', $row),
            'category'      => $data['category'] ?? null,
            'subcategory'   => $data['subcategory'] ?? null,
            'unit_cost'     => isset($data['unit_cost'])     ? $this->numericOrNull($data['unit_cost'])     : null,
            'selling_price' => isset($data['selling_price']) ? $this->numericOrNull($data['selling_price']) : null,
            'supplier'      => $data['supplier'] ?? null,
            'barcode'       => $data['barcode'] ?? null,
            'brand'         => $data['brand'] ?? null,
            'pack_size'     => $data['pack_size'] ?? null,
        ];

        Product::updateOrCreate(
            ['tenant_id' => $attrs['tenant_id'], 'sku' => $attrs['sku']],
            $attrs
        );
    }

    private function writePurchaseOrder(Import $import, array $data, int $row): void
    {
        PurchaseOrder::create($this->buildPurchaseOrderAttrs($import, $data, $row));
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
            'expected_date' => isset($data['expected_date']) ? $this->parseDateOrNull($data['expected_date']) : null,
            'received_date' => isset($data['received_date']) ? $this->parseDateOrNull($data['received_date']) : null,
            'location'      => $data['location'] ?? null,
            'open_qty'      => isset($data['open_qty'])  ? $this->numericOrNull($data['open_qty'])  : null,
            'late_days'     => isset($data['late_days']) ? (int) $this->numericOrNull($data['late_days']) : null,
            'fill_rate'     => isset($data['fill_rate']) ? $this->numericOrNull($data['fill_rate']) : null,
        ];

        if (! empty($data['location'])) {
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

        $attrs = [
            'tenant_id' => $import->tenant_id,
            'name'      => $this->str($data['name'], 'name', $row),
            'code'      => $data['code'] ?? null,
            'address'   => $data['address'] ?? null,
            'city'      => $data['city'] ?? null,
            'region'    => $data['region'] ?? null,
            'country'   => $data['country'] ?? null,
            'format'    => $data['format'] ?? null,
        ];

        // Enrich the existing (possibly auto-created) store rather than duplicating.
        Store::updateOrCreate(
            ['tenant_id' => $attrs['tenant_id'], 'name' => $attrs['name']],
            $attrs
        );
    }

    private function writeSupplier(Import $import, array $data, int $row): void
    {
        $this->requireFields($data, ['name'], $row);

        $attrs = [
            'tenant_id'      => $import->tenant_id,
            'name'           => $this->str($data['name'], 'name', $row),
            'code'           => $data['code'] ?? null,
            'lead_time_days' => isset($data['lead_time_days']) ? (int) $this->numericOrNull($data['lead_time_days']) : null,
            'contact_email'  => $data['contact_email'] ?? null,
            'contact_phone'  => $data['contact_phone'] ?? null,
            'type'           => $data['type'] ?? null,
            'specialization' => $data['specialization'] ?? null,
        ];

        Supplier::updateOrCreate(
            ['tenant_id' => $attrs['tenant_id'], 'name' => $attrs['name']],
            $attrs
        );
    }

    private function writeReturn(Import $import, array $data, int $row): void
    {
        SalesReturn::create($this->buildReturnAttrs($import, $data, $row));
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
            'reason'    => $data['reason'] ?? null,
            'return_id' => $data['return_id'] ?? null,
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

        $role = strtolower(trim((string) ($data['role'] ?? '')));
        $isAdmin = in_array($role, ['admin', 'tenant_admin', 'tenant admin', 'administrator', 'manager'], true);

        $existing = User::where('email', $email)->first();

        $attrs = [
            'tenant_id'       => $import->tenant_id,
            'name'            => $this->str($data['name'], 'name', $row),
            'is_tenant_admin' => $isAdmin,
        ];

        if ($existing) {
            // Never let an import escalate a super admin or move another tenant's user.
            if ($existing->tenant_id !== $import->tenant_id) {
                throw new \InvalidArgumentException("Row {$row}: user '{$email}' already exists under a different tenant.");
            }
            $existing->update($attrs);
        } else {
            // Random password — imported users must have one set by an admin
            // (no self-service reset until MAIL_* is configured).
            $attrs['email']    = $email;
            $attrs['password'] = Str::random(40);
            User::create($attrs);
        }
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
                $data[$map->target_field] = $value !== '' ? $value : null;
            }
        }
        return $data;
    }

    private function readAllRows(string $filePath): array
    {
        // Read data only (skip styles/formatting) to keep memory down on large files.
        $reader      = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $sheet       = $spreadsheet->getActiveSheet();
        $data        = $sheet->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        if (empty($data)) {
            return [];
        }

        $headers = array_map('trim', array_map('strval', array_shift($data)));
        $rows    = [];

        foreach ($data as $row) {
            $rowData = [];
            foreach ($headers as $i => $header) {
                // toArray() uses formatData = true, so Excel dates are already formatted.
                $value = $row[$i] ?? null;
                $rowData[$header] = $value !== null ? (string) $value : '';
            }

            if (!empty(array_filter($rowData))) {
                $rows[] = $rowData;
            }
        }

        return $rows;
    }

    private function requireFields(array $data, array $fields, int $row): void
    {
        foreach ($fields as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Row {$row}: required field '{$field}' is missing or empty.");
            }
        }
    }

    private function parseDate(string $value, int $row): string
    {
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            throw new \InvalidArgumentException("Row {$row}: cannot parse '{$value}' as a date.");
        }
    }

    private function parseDateOrNull(?string $value): ?string
    {
        if (empty($value)) return null;
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function numeric(?string $value, string $field, int $row): float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', $value ?? '');
        if (!is_numeric($clean)) {
            throw new \InvalidArgumentException("Row {$row}: '{$field}' value '{$value}' is not a valid number.");
        }
        return (float) $clean;
    }

    private function numericOrNull(?string $value): ?float
    {
        if (empty($value)) return null;
        $clean = preg_replace('/[^0-9.\-]/', '', $value);
        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * Numeric value where a blank/missing cell means 0 (e.g. a stock quantity
     * written as an empty cell by a CSV export). Non-numeric junk also falls
     * back to 0 rather than rejecting the row.
     */
    private function numericOrZero(?string $value): float
    {
        if ($value === null || trim((string) $value) === '') {
            return 0.0;
        }
        $clean = preg_replace('/[^0-9.\-]/', '', $value);
        return is_numeric($clean) ? (float) $clean : 0.0;
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
        $name = trim($locationName);

        if ($this->storeCache !== null && $this->cachedTenantId === $tenantId && isset($this->storeCache[$name])) {
            return $this->storeCache[$name];
        }

        $store = Store::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => $name],
            ['tenant_id' => $tenantId, 'name' => $name]
        );

        if ($this->storeCache !== null && $this->cachedTenantId === $tenantId) {
            $this->storeCache[$name] = (int) $store->id;
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

        if ($this->supplierCache !== null && $this->cachedTenantId === $tenantId && isset($this->supplierCache[$name])) {
            return $this->supplierCache[$name];
        }

        $supplier = Supplier::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => $name],
            ['tenant_id' => $tenantId, 'name' => $name]
        );

        if ($this->supplierCache !== null && $this->cachedTenantId === $tenantId) {
            $this->supplierCache[$name] = (int) $supplier->id;
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
