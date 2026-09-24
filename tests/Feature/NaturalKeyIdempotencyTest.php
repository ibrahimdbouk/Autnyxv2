<?php

namespace Tests\Feature;

use App\Jobs\RunTenantDetectionJob;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\InventoryLevel;
use App\Models\PurchaseOrder;
use App\Models\QuarantinedRow;
use App\Models\SalesReturn;
use App\Models\SalesTransaction;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Import\ImportProcessorService;
use App\Services\Sales\SalesDailyAggregator;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WP3.5 (audit H27, H28 dup, M26 chunk, retry) — re-sending data never doubles
 * it, an undo leaves no phantom demand, concurrent polls can't double a chunk,
 * and a retried row goes through the full pipeline.
 */
class NaturalKeyIdempotencyTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        ImportProcessorService::forgetNaturalKeys();
        $this->tenant = $this->createTenant();
        Storage::fake('local');
    }

    private function import(string $type, array $map, string $csv): Import
    {
        $path = 'imports/pending/' . uniqid() . '.csv';
        Storage::disk('local')->put($path, $csv);
        $import = Import::create([
            'tenant_id' => $this->tenant->id, 'original_filename' => 'f.csv', 'disk' => 'local', 'path' => $path,
            'data_type' => $type, 'status' => Import::STATUS_UPLOADED, 'total_rows' => substr_count(trim($csv), "\n"), 'date_format' => 'Y-m-d',
        ]);
        foreach ($map as $h => $f) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $f, 'is_skipped' => false, 'is_confirmed' => true]);
        }

        return $import;
    }

    private function runImport(Import $import, int $chunk = 100): Import
    {
        $svc = app(ImportProcessorService::class);
        $svc->startChunkedImport($import);
        $guard = 0;
        do {
            $r = $svc->processChunk($import->fresh(), $chunk);
        } while (! ($r['done'] ?? false) && ++$guard < 20);

        return $import->fresh();
    }

    private const INV = ['SKU' => 'sku', 'Store' => 'location', 'OnHand' => 'on_hand_qty', 'AsOf' => 'as_of_date'];

    public function test_a_re_sent_inventory_snapshot_updates_in_place(): void
    {
        $this->runImport($this->import(Import::TYPE_INVENTORY, self::INV, "SKU,Store,OnHand,AsOf\nA,Downtown,10,2026-04-03\nB,Downtown,5,2026-04-03\n"));
        $second = $this->runImport($this->import(Import::TYPE_INVENTORY, self::INV, "SKU,Store,OnHand,AsOf\nA,Downtown,7,2026-04-03\n"));

        $this->assertSame(2, InventoryLevel::count(), 'no doubled position');
        $a = InventoryLevel::where('sku', 'A')->sole();
        $this->assertEqualsWithDelta(7, (float) $a->on_hand_qty, 0.001, 'the newest snapshot wins');
        $this->assertSame($second->id, (int) $a->import_id);
    }

    public function test_a_snapshot_without_a_date_is_the_import_days_snapshot(): void
    {
        $import = $this->runImport($this->import(Import::TYPE_INVENTORY, ['SKU' => 'sku', 'OnHand' => 'on_hand_qty'], "SKU,OnHand\nA,3\n"));

        $this->assertSame($import->created_at->toDateString(), \Illuminate\Support\Carbon::parse(InventoryLevel::sole()->as_of_date)->toDateString());
    }

    public function test_a_re_sent_po_line_is_updated_and_a_re_sent_return_is_skipped(): void
    {
        $poMap = ['PO' => 'po_number', 'Supplier' => 'supplier', 'SKU' => 'sku', 'Ordered' => 'qty_ordered', 'Received' => 'qty_received', 'Date' => 'order_date'];
        $this->runImport($this->import(Import::TYPE_PURCHASE_ORDERS, $poMap, "PO,Supplier,SKU,Ordered,Received,Date\nP1,Acme,A,100,0,2026-04-01\n"));
        $this->runImport($this->import(Import::TYPE_PURCHASE_ORDERS, $poMap, "PO,Supplier,SKU,Ordered,Received,Date\nP1,Acme,A,100,60,2026-04-01\n"));
        $this->assertSame(1, PurchaseOrder::count());
        $this->assertEqualsWithDelta(60, (float) PurchaseOrder::sole()->qty_received, 0.001);

        $retMap = ['Id' => 'return_id', 'Date' => 'date', 'SKU' => 'sku', 'Qty' => 'quantity'];
        $this->runImport($this->import(Import::TYPE_RETURNS, $retMap, "Id,Date,SKU,Qty\nR1,2026-04-02,A,1\n"));
        $again = $this->runImport($this->import(Import::TYPE_RETURNS, $retMap, "Id,Date,SKU,Qty\nR1,2026-04-02,A,1\nR2,2026-04-02,B,1\n"));
        $this->assertSame(2, SalesReturn::count());
        $this->assertSame(1, (int) $again->duplicate_rows);
    }

    public function test_an_identical_file_is_reloaded_after_the_original_was_rolled_back(): void
    {
        $csv = "SKU,Store,OnHand,AsOf\nA,Downtown,10,2026-04-03\n";
        $first = $this->runImport($this->import(Import::TYPE_INVENTORY, self::INV, $csv));
        app(ImportProcessorService::class)->rollback($first);

        $again = $this->runImport($this->import(Import::TYPE_INVENTORY, self::INV, $csv));

        $this->assertNotSame(Import::STATUS_ROLLED_BACK, $again->status, 'not skipped as a duplicate of an undone import');
        $this->assertSame(1, InventoryLevel::count());
    }

    public function test_two_polls_of_the_same_chunk_write_it_once(): void
    {
        $import = $this->import(Import::TYPE_RETURNS, ['Date' => 'date', 'SKU' => 'sku', 'Qty' => 'quantity'], "Date,SKU,Qty\n2026-04-02,A,1\n2026-04-02,B,2\n");
        $svc = app(ImportProcessorService::class);
        $svc->startChunkedImport($import);

        // Another poll claims the window while this one is reading the file.
        $this->app->instance(\App\Services\Import\FileReaderService::class, new class($import->id) extends \App\Services\Import\FileReaderService {
            public function __construct(private int $importId) {}
            public function readRange(string $path, int $offset, int $limit, array $dialect = []): array
            {
                $window = parent::readRange($path, $offset, $limit, $dialect);
                Import::whereKey($this->importId)->update(['process_cursor' => $offset + $window['consumed']]);

                return $window;
            }
        });

        $svc->processChunk($import->fresh(), 10);

        $this->assertSame(0, SalesReturn::count(), 'the losing poll writes nothing');
        $this->assertSame(0, (int) $import->fresh()->imported_rows);
    }

    public function test_undoing_a_sales_import_leaves_no_phantom_demand(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Downtown']);
        $import = $this->runImport($this->import(Import::TYPE_SALES, ['Date' => 'date', 'Receipt' => 'transaction_id', 'SKU' => 'sku', 'Store' => 'location', 'Qty' => 'quantity'],
            "Date,Receipt,SKU,Store,Qty\n2026-04-03,R1,A,Downtown,1\n2026-04-03,R1,A,Downtown,2\n2026-04-03,R2,A,Downtown,1\n"));

        app(SalesDailyAggregator::class)->aggregateForImport($import);
        $day = DB::table('sales_daily')->where('tenant_id', $this->tenant->id)->where('store_id', $store->id)->where('sku', 'A')->first();
        $this->assertEqualsWithDelta(4, (float) $day->units_sold, 0.001);
        $this->assertSame(2, (int) $day->transaction_count, 'two receipts, three lines');

        app(ImportProcessorService::class)->rollback($import->fresh());

        $this->assertSame(0, DB::table('sales_daily')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_a_retried_row_is_screened_and_runs_the_post_import_hooks(): void
    {
        Bus::fake([RunTenantDetectionJob::class]);
        $import = $this->import(Import::TYPE_SALES, [], "x\n");
        $good = ImportRow::create(['import_id' => $import->id, 'tenant_id' => $this->tenant->id, 'row_number' => 2, 'raw_data' => ['a' => 1],
            'mapped_data' => ['date' => '2026-04-03', 'sku' => 'A', 'quantity' => '1', 'location' => 'Downtown'], 'error_message' => 'x', 'status' => ImportRow::STATUS_PENDING]);
        $keyless = ImportRow::create(['import_id' => $import->id, 'tenant_id' => $this->tenant->id, 'row_number' => 3, 'raw_data' => ['a' => 2],
            'mapped_data' => ['date' => '2026-04-03', 'sku' => null, 'quantity' => '1'], 'error_message' => 'x', 'status' => ImportRow::STATUS_PENDING]);

        $r = app(ImportProcessorService::class)->retryRows($import, collect([$good, $keyless]));

        $this->assertSame(1, $r['retried']);
        $this->assertSame(1, $r['quarantined'], 'a row with no SKU is quarantined, not re-failed');
        $this->assertSame('missing_key', QuarantinedRow::where('import_id', $import->id)->value('reason_code'));
        $this->assertSame(1, DB::table('sales_daily')->where('tenant_id', $this->tenant->id)->count(), 'aggregate updated');
        Bus::assertDispatched(RunTenantDetectionJob::class);
    }

    public function test_the_dedupe_command_keeps_the_newest_row_per_key(): void
    {
        DB::statement('DROP INDEX IF EXISTS inventory_levels_natural_key');
        ImportProcessorService::forgetNaturalKeys();
        foreach ([10, 20] as $qty) {
            InventoryLevel::create(['tenant_id' => $this->tenant->id, 'sku' => 'A', 'on_hand_qty' => $qty, 'as_of_date' => '2026-04-03']);
        }

        $this->artisan('imports:dedupe-natural-keys')->assertSuccessful();
        $this->assertSame(2, InventoryLevel::count(), 'dry run deletes nothing');

        $this->artisan('imports:dedupe-natural-keys', ['--apply' => true])->assertSuccessful();
        $this->assertEqualsWithDelta(20, (float) InventoryLevel::sole()->on_hand_qty, 0.001);
        $this->assertSame(0, \App\Support\Database\NaturalKeyIndexes::duplicates('inventory_levels'));
        $this->assertSame(1, (int) DB::table('wp35_dedupe_backup_inventory_levels')->count(), 'the removed row is backed up');
        $this->assertNotNull(DB::selectOne("select 1 as x from pg_class where relname = 'inventory_levels_natural_key'"), 'and the index is created');
    }
}
