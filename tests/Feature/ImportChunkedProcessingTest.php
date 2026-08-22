<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\InventoryLevel;
use App\Models\SalesReturn;
use App\Models\Store;
use App\Services\Import\ImportProcessorService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exercises the chunked, poll-driven import path (startChunkedImport +
 * processChunk) end to end against a real CSV, including the header-only
 * final tick that finalises the import. Regression guard for the large-file
 * background-processing work (Deploy 2).
 */
class ImportChunkedProcessingTest extends TestCase
{
    private \App\Models\Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        Storage::fake('local');
    }

    private function makeReturnsImport(string $csv, int $totalRows): Import
    {
        Storage::disk('local')->put('imports/pending/returns.csv', $csv);

        $import = Import::create([
            'tenant_id'         => $this->tenant->id,
            'original_filename' => 'returns.csv',
            'disk'              => 'local',
            'path'              => 'imports/pending/returns.csv',
            'data_type'         => Import::TYPE_RETURNS,
            'status'            => Import::STATUS_UPLOADED,
            'total_rows'        => $totalRows,
        ]);

        // A confirmed column map: header -> canonical field.
        foreach ([
            'Date'     => 'date',
            'SKU'      => 'sku',
            'Store'    => 'location',
            'Qty'      => 'quantity',
            'Value'    => 'value',
            'Reason'   => 'reason',
        ] as $header => $field) {
            $import->columnMaps()->create([
                'source_header' => $header,
                'target_field'  => $field,
                'is_skipped'    => false,
                'is_confirmed'  => true,
            ]);
        }

        return $import;
    }

    public function test_chunked_import_processes_all_rows_across_ticks(): void
    {
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = "2026-08-0" . (($i % 9) + 1) . ",SKU-{$i},Downtown,{$i},{$i}.50,Defective";
        }
        $csv = "Date,SKU,Store,Qty,Value,Reason\n" . implode("\n", $rows) . "\n";

        $import = $this->makeReturnsImport($csv, 25);

        $service = app(ImportProcessorService::class);
        $service->startChunkedImport($import);

        $this->assertSame(Import::STATUS_IMPORTING, $import->fresh()->status);

        // Small chunk size so it takes several ticks (like real polling).
        $guard = 0;
        do {
            $result = $service->processChunk($import->fresh(), 10);
        } while (! ($result['done'] ?? false) && ++$guard < 20);

        $import->refresh();

        $this->assertTrue($import->isCompleted(), "Import should have completed, status was {$import->status}");
        $this->assertSame(25, (int) $import->imported_rows);
        $this->assertSame(0, (int) $import->failed_rows);
        $this->assertSame(25, SalesReturn::where('import_id', $import->id)->count());

        // The store referenced by every row was auto-resolved just once.
        $this->assertSame(1, Store::where('tenant_id', $this->tenant->id)->where('name', 'Downtown')->count());
    }

    public function test_blank_stock_quantity_imports_as_zero(): void
    {
        $csv = "SKU,Store,OnHand\n"
            . "SKU-1,Downtown,5\n"
            . "SKU-2,Downtown,\n"   // blank -> should import as 0, not fail
            . "SKU-3,Downtown,0\n";

        Storage::disk('local')->put('imports/pending/inv.csv', $csv);

        $import = Import::create([
            'tenant_id'         => $this->tenant->id,
            'original_filename' => 'inv.csv',
            'disk'              => 'local',
            'path'              => 'imports/pending/inv.csv',
            'data_type'         => Import::TYPE_INVENTORY,
            'status'            => Import::STATUS_UPLOADED,
            'total_rows'        => 3,
        ]);

        foreach (['SKU' => 'sku', 'Store' => 'location', 'OnHand' => 'on_hand_qty'] as $header => $field) {
            $import->columnMaps()->create([
                'source_header' => $header,
                'target_field'  => $field,
                'is_skipped'    => false,
                'is_confirmed'  => true,
            ]);
        }

        $service = app(ImportProcessorService::class);
        $service->startChunkedImport($import);
        $guard = 0;
        do {
            $result = $service->processChunk($import->fresh(), 100);
        } while (! ($result['done'] ?? false) && ++$guard < 5);

        $import->refresh();

        $this->assertSame(3, (int) $import->imported_rows, 'blank qty row should import, not fail');
        $this->assertSame(0, (int) $import->failed_rows);

        $blank = InventoryLevel::where('tenant_id', $this->tenant->id)->where('sku', 'SKU-2')->first();
        $this->assertNotNull($blank);
        $this->assertEqualsWithDelta(0.0, (float) $blank->on_hand_qty, 0.001);
    }

    public function test_chunked_import_records_failed_rows_without_aborting(): void
    {
        // Row 2 is missing the required quantity -> should land in the failed ledger,
        // while the other rows still import.
        $csv = "Date,SKU,Store,Qty,Value,Reason\n"
            . "2026-08-01,SKU-1,Downtown,4,4.00,Defective\n"
            . "2026-08-02,SKU-2,Downtown,,2.00,Defective\n"
            . "2026-08-03,SKU-3,Downtown,6,6.00,Defective\n";

        $import = $this->makeReturnsImport($csv, 3);

        $service = app(ImportProcessorService::class);
        $service->startChunkedImport($import);

        $guard = 0;
        do {
            $result = $service->processChunk($import->fresh(), 100);
        } while (! ($result['done'] ?? false) && ++$guard < 5);

        $import->refresh();

        $this->assertSame(2, (int) $import->imported_rows);
        $this->assertSame(1, (int) $import->failed_rows);
        $this->assertSame(Import::STATUS_COMPLETED_WITH_ERRORS, $import->status);
        $this->assertSame(2, SalesReturn::where('import_id', $import->id)->count());
    }
}
