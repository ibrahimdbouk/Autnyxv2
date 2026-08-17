<?php

namespace App\Services\Import;

use App\Models\Import;
use App\Models\ImportColumnMap;
use App\Models\ImportRow;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesTransaction;
use App\Models\Store;
use App\Services\Anomaly\AnomalyDetectionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Applies a confirmed column mapping to the uploaded file and writes rows to the DB.
 * Failed rows are stored in import_rows for review.
 */
class ImportProcessorService
{
    public function process(Import $import): void
    {
        $import->update(['status' => Import::STATUS_IMPORTING]);

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

    private function writeRow(Import $import, $columnMap, array $rawRow, int $rowNumber): void
    {
        $data = $this->applyMap($columnMap, $rawRow);

        match ($import->data_type) {
            Import::TYPE_SALES           => $this->writeSalesTransaction($import, $data, $rowNumber),
            Import::TYPE_INVENTORY       => $this->writeInventoryLevel($import, $data, $rowNumber),
            Import::TYPE_PRODUCTS        => $this->writeProduct($import, $data, $rowNumber),
            Import::TYPE_PURCHASE_ORDERS => $this->writePurchaseOrder($import, $data, $rowNumber),
            default                      => throw new \InvalidArgumentException("Unknown data type: {$import->data_type}"),
        };
    }

    private function writeSalesTransaction(Import $import, array $data, int $row): void
    {
        $this->requireFields($data, ['date', 'sku', 'quantity'], $row);

        $location = $data['location'] ?? null;

        $attrs = [
            'tenant_id'      => $import->tenant_id,
            'date'           => $this->parseDate($data['date'], $row),
            'sku'            => $this->str($data['sku'] ?? null, 'sku', $row),
            'location'       => $location,
            'quantity'       => $this->numeric($data['quantity'] ?? null, 'quantity', $row),
            'unit_price'     => isset($data['unit_price'])  ? $this->numericOrNull($data['unit_price'])  : null,
            'total_amount'   => isset($data['total_amount']) ? $this->numericOrNull($data['total_amount']) : null,
            'transaction_id' => $data['transaction_id'] ?? null,
        ];

        // Resolve store from location string
        if ($location) {
            $attrs['store_id'] = $this->resolveStore($import->tenant_id, $location);
        }

        // Try to resolve product_id from SKU
        $product = Product::where('tenant_id', $import->tenant_id)->where('sku', $attrs['sku'])->first();
        if ($product) {
            $attrs['product_id'] = $product->id;
        }

        $uniqueKey = array_filter(['tenant_id' => $attrs['tenant_id'], 'transaction_id' => $attrs['transaction_id']]);

        if ($attrs['transaction_id'] && SalesTransaction::where($uniqueKey)->exists()) {
            // Upsert by transaction_id
            SalesTransaction::where($uniqueKey)->update($attrs);
        } else {
            SalesTransaction::create($attrs);
        }
    }

    private function writeInventoryLevel(Import $import, array $data, int $row): void
    {
        $this->requireFields($data, ['sku', 'on_hand_qty'], $row);

        $location = $data['location'] ?? null;

        $attrs = [
            'tenant_id'     => $import->tenant_id,
            'sku'           => $this->str($data['sku'], 'sku', $row),
            'location'      => $location,
            'on_hand_qty'   => $this->numeric($data['on_hand_qty'], 'on_hand_qty', $row),
            'reorder_point' => isset($data['reorder_point']) ? $this->numericOrNull($data['reorder_point']) : null,
            'as_of_date'    => isset($data['as_of_date']) ? $this->parseDateOrNull($data['as_of_date']) : null,
        ];

        // Resolve store from location string
        if ($location) {
            $attrs['store_id'] = $this->resolveStore($import->tenant_id, $location);
        }

        $product = Product::where('tenant_id', $import->tenant_id)->where('sku', $attrs['sku'])->first();
        if ($product) {
            $attrs['product_id'] = $product->id;
        }

        InventoryLevel::create($attrs);
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
        ];

        Product::updateOrCreate(
            ['tenant_id' => $attrs['tenant_id'], 'sku' => $attrs['sku']],
            $attrs
        );
    }

    private function writePurchaseOrder(Import $import, array $data, int $row): void
    {
        $this->requireFields($data, ['po_number', 'supplier', 'sku', 'qty_ordered', 'order_date'], $row);

        $attrs = [
            'tenant_id'     => $import->tenant_id,
            'po_number'     => $this->str($data['po_number'], 'po_number', $row),
            'supplier'      => $this->str($data['supplier'], 'supplier', $row),
            'sku'           => $this->str($data['sku'], 'sku', $row),
            'qty_ordered'   => $this->numeric($data['qty_ordered'], 'qty_ordered', $row),
            'qty_received'  => isset($data['qty_received'])  ? $this->numericOrNull($data['qty_received'])  : null,
            'unit_cost'     => isset($data['unit_cost'])     ? $this->numericOrNull($data['unit_cost'])     : null,
            'order_date'    => $this->parseDate($data['order_date'], $row),
            'expected_date' => isset($data['expected_date']) ? $this->parseDateOrNull($data['expected_date']) : null,
            'received_date' => isset($data['received_date']) ? $this->parseDateOrNull($data['received_date']) : null,
        ];

        $product = Product::where('tenant_id', $import->tenant_id)->where('sku', $attrs['sku'])->first();
        if ($product) {
            $attrs['product_id'] = $product->id;
        }

        PurchaseOrder::create($attrs);
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
        $spreadsheet = IOFactory::load($filePath);
        $sheet       = $spreadsheet->getActiveSheet();
        $data        = $sheet->toArray(null, true, true, false);

        if (empty($data)) {
            return [];
        }

        $headers = array_map('trim', array_map('strval', array_shift($data)));
        $rows    = [];

        foreach ($data as $row) {
            $rowData = [];
            foreach ($headers as $i => $header) {
                $value = $row[$i] ?? null;
                if (is_numeric($value) && ExcelDate::isDateTimeValue($value)) {
                    try {
                        $value = ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
                    } catch (\Exception) {}
                }
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
        $store = Store::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => trim($locationName)],
            ['tenant_id' => $tenantId, 'name' => trim($locationName)]
        );

        return $store->id;
    }
}
