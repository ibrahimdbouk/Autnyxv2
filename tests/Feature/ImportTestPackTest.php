<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Product;
use App\Models\QuarantinedRow;
use App\Models\SalesTransaction;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Import\ImportProcessorService;
use App\Services\Sales\SalesDailyAggregator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WP3.8 — the import test pack: one realistic UAE retailer journey end to end,
 * on PostgreSQL, through the same screen → write pipeline the app uses.
 *
 * Detailed cases live next to their fix:
 *   • dates / decimals / dialects / encodings / Excel cells .. ImportParsingTest (WP3.2)
 *   • multi-line receipts, retry, duplicate-receipt rule ..... SalesLineIdentityTest (WP3.1)
 *   • mapping collisions, AI mapper, memory, review gate ...... MappingEngineTest (WP3.3)
 *   • new fields, units, partial master data, stores .......... ImportFieldsTest (WP3.4)
 *   • natural keys, rollback → sales_daily, chunk race ........ NaturalKeyIdempotencyTest (WP3.5)
 *   • screening, RED hold, quarantine repair loop ............. ScreeningAndHoldTest (WP3.6)
 *   • SFTP overwrite / partial upload / retry, API 429 / caps . ConnectorReliabilityTest (WP3.7)
 */
class ImportTestPackTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => null]);
        $this->tenant = $this->createTenant(['settings' => ['import_date_format' => 'd/m/Y', 'import_decimal_separator' => ',']]);
        Storage::fake('local');
    }

    private function load(string $type, string $csv, array $map): Import
    {
        $path = 'imports/pending/' . uniqid() . '.csv';
        Storage::disk('local')->put($path, $csv);
        $import = Import::create([
            'tenant_id' => $this->tenant->id, 'original_filename' => 'f.csv', 'disk' => 'local', 'path' => $path,
            'data_type' => $type, 'status' => Import::STATUS_MAPPING_REVIEW, 'total_rows' => substr_count(trim($csv), "\n"),
            'mapping_confirmed_at' => now(),
        ]);
        foreach ($map as $h => $f) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $f, 'is_skipped' => false, 'is_confirmed' => true]);
        }
        $svc = app(ImportProcessorService::class);
        $svc->startChunkedImport($import);
        $guard = 0;
        do {
            $r = $svc->processChunk($import->fresh(), 2);
        } while (! ($r['done'] ?? false) && ++$guard < 100);

        return $import->fresh();
    }

    public function test_a_uae_retailer_journey_from_master_data_to_undo(): void
    {
        // 1. Store master (codes the POS uses) and products (EU decimals, VAT, kg).
        $this->load(Import::TYPE_STORES, "Name;Code;City\nDubai Mall;ST001;Dubai\nAbu Dhabi Mall;ST002;Abu Dhabi\n",
            ['Name' => 'name', 'Code' => 'code', 'City' => 'city']);
        $this->load(Import::TYPE_PRODUCTS, "SKU;Name;Price;VAT;Weight (kg)\n00123;Laban 1L;4,50;5%;1,03\n00456;Rice 5kg;32,75;5%;5\n",
            ['SKU' => 'sku', 'Name' => 'name', 'Price' => 'selling_price', 'VAT' => 'tax_rate', 'Weight (kg)' => 'weight_grams']);

        $laban = Product::where('sku', '00123')->sole();
        $this->assertEqualsWithDelta(4.5, (float) $laban->selling_price, 0.001);
        $this->assertEqualsWithDelta(1030, (float) $laban->weight_grams, 0.01);

        // 2. POS extract: semicolons, dd/mm dates, comma decimals, stores by code,
        //    multi-line receipts (the same item scanned twice is two lines).
        $sales = "Date;Receipt;SKU;Store;Qty;Total\n"
            . "03/04/2026;R1;00123;ST001;1;4,50\n"
            . "03/04/2026;R1;00456;ST001;1;32,75\n"
            . "03/04/2026;R1;00123;ST001;1;4,50\n"
            . "13/04/2026;R2;00123;st002 ;2;9,00\n";
        $map = ['Date' => 'date', 'Receipt' => 'transaction_id', 'SKU' => 'sku', 'Store' => 'location', 'Qty' => 'quantity', 'Total' => 'total_amount'];
        $first = $this->load(Import::TYPE_SALES, $sales, $map);

        $this->assertSame(Import::STATUS_COMPLETED, $first->status);
        $this->assertSame(4, SalesTransaction::count(), 'every receipt line kept');
        $this->assertSame(2, Store::where('tenant_id', $this->tenant->id)->count(), 'codes resolve to the master stores');
        $this->assertSame('2026-04-13', SalesTransaction::where('transaction_id', 'R2')->sole()->date->toDateString(), '13/04 read day-first');

        app(SalesDailyAggregator::class)->aggregateForImport($first);
        $day = DB::table('sales_daily')->where('sku', '00123')->where('date', '2026-04-03')->sole();
        $this->assertEqualsWithDelta(2, (float) $day->units_sold, 0.001);
        $this->assertSame(1, (int) $day->transaction_count, 'one receipt');

        // 3. Tomorrow's extract repeats yesterday + ten new receipts and one bad row
        //    (≥90% clean, so it loads; a dirtier file would be held — see ScreeningAndHoldTest).
        $tomorrow = $sales;
        for ($i = 10; $i < 20; $i++) {
            $tomorrow .= "14/04/2026;R{$i};00456;ST002;1;32,75\n";
        }
        $again = $this->load(Import::TYPE_SALES, $tomorrow . "14/04/2026;R99;;ST002;1;1,00\n", $map);

        $this->assertSame(10, (int) $again->imported_rows);
        $this->assertSame(4, (int) $again->duplicate_rows, 'yesterday is skipped, not doubled');
        $this->assertSame(1, QuarantinedRow::where('import_id', $again->id)->count(), 'the keyless row is quarantined');
        $this->assertSame(Import::STATUS_COMPLETED_WITH_ERRORS, $again->status);

        // 4. Undo the first import → its demand disappears from sales_daily.
        app(ImportProcessorService::class)->rollback($first->fresh());
        $this->assertSame(0, DB::table('sales_daily')->where('date', '2026-04-03')->count());
        $this->assertSame(10, SalesTransaction::count(), 'only the second import\'s new receipts remain');
    }
}
