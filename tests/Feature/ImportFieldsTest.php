<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\ImportQuality;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Services\Import\ColumnMappingService;
use App\Services\Import\ImportProcessorService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WP3.4 (pending item P2, audit H25) — the hardening fields and dimensions are
 * ingested; unusable optional values become NULL with a warning (never a
 * failed row); units are converted; master data updates only what the file
 * carries; stores resolve regardless of case, spacing or name-vs-code.
 */
class ImportFieldsTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => null]);
        $this->tenant = $this->createTenant();
        Storage::fake('local');
    }

    /** Import a CSV with each header mapped to the given field. */
    private function load(string $type, array $headerToField, array $rows): Import
    {
        $lines = [implode(',', array_keys($headerToField))];
        foreach ($rows as $r) {
            $lines[] = implode(',', array_map(fn ($v) => str_contains((string) $v, ',') ? '"' . $v . '"' : $v, $r));
        }
        $path = 'imports/pending/' . uniqid() . '.csv';
        Storage::disk('local')->put($path, implode("\n", $lines) . "\n");

        $import = Import::create([
            'tenant_id' => $this->tenant->id, 'original_filename' => 'f.csv', 'disk' => 'local', 'path' => $path,
            'data_type' => $type, 'status' => Import::STATUS_UPLOADED, 'total_rows' => count($rows), 'date_format' => 'Y-m-d',
        ]);
        foreach ($headerToField as $h => $f) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $f, 'is_skipped' => false, 'is_confirmed' => true]);
        }

        $svc = app(ImportProcessorService::class);
        $svc->startChunkedImport($import);
        $guard = 0;
        do {
            $r = $svc->processChunk($import->fresh(), 100);
        } while (! ($r['done'] ?? false) && ++$guard < 10);

        return $import->fresh();
    }

    public function test_sales_hardening_fields_land_and_a_bad_optional_value_is_emptied_not_failed(): void
    {
        $import = $this->load(Import::TYPE_SALES,
            ['Date' => 'date', 'SKU' => 'sku', 'Store' => 'location', 'Qty' => 'quantity', 'Channel' => 'channel', 'Cost' => 'cost_amount', 'Cur' => 'currency', 'Cust' => 'customer_ref'],
            [['2026-04-03', 'S1', 'Downtown', '2', 'online', '7.25', 'AEDX', 'C-9']]);

        $this->assertSame(1, (int) $import->imported_rows);
        $this->assertSame(0, (int) $import->failed_rows, 'a 4-letter currency no longer fails the row (or the batch)');
        $row = SalesTransaction::first();
        $this->assertSame('online', $row->channel);
        $this->assertEqualsWithDelta(7.25, (float) $row->cost_amount, 0.0001);
        $this->assertNull($row->currency);
        $this->assertSame('C-9', $row->customer_ref);
        $this->assertSame(1, ImportQuality::where('import_id', $import->id)->first()->reason_counts['warn_invalid_value'] ?? 0);
    }

    public function test_inventory_lot_fields_land(): void
    {
        $this->load(Import::TYPE_INVENTORY,
            ['SKU' => 'sku', 'Store' => 'location', 'On Hand' => 'on_hand_qty', 'SS' => 'safety_stock', 'Lot' => 'batch_ref', 'Expiry' => 'expiry_date'],
            [['S1', 'Downtown', '10', '4', 'L-77', '2026-12-31']]);

        $inv = InventoryLevel::first();
        $this->assertEqualsWithDelta(4, (float) $inv->safety_stock, 0.001);
        $this->assertSame('L-77', $inv->batch_ref);
        $this->assertSame('2026-12-31', \Illuminate\Support\Carbon::parse($inv->expiry_date)->toDateString());
    }

    public function test_product_units_are_converted_and_volume_is_derived(): void
    {
        $this->load(Import::TYPE_PRODUCTS,
            ['SKU' => 'sku', 'Name' => 'name', 'VAT' => 'tax_rate', 'Weight (kg)' => 'weight_grams', 'Length (cm)' => 'length_mm', 'Width' => 'width_mm', 'Height' => 'height_mm', 'GTIN' => 'gtin'],
            [
                ['P1', 'Milk', '5%', '1.5', '10', '100 mm', '0.2 m', '6291041500213'],
                ['P2', 'Rice', '0.15', '500 g', '', '', '', 'not-a-gtin'],
            ]);

        $p1 = Product::where('sku', 'P1')->first();
        $this->assertEqualsWithDelta(5, (float) $p1->tax_rate, 0.001);
        $this->assertEqualsWithDelta(1500, (float) $p1->weight_grams, 0.01, 'kg from the header');
        $this->assertEqualsWithDelta(100, (float) $p1->length_mm, 0.01, 'cm from the header');
        $this->assertEqualsWithDelta(200, (float) $p1->height_mm, 0.01, 'm in the value');
        $this->assertEqualsWithDelta(2000, (float) $p1->volume_cm3, 0.01, '100×100×200 mm = 2000 cm³');
        $this->assertSame('6291041500213', $p1->gtin);

        $p2 = Product::where('sku', 'P2')->first();
        $this->assertEqualsWithDelta(15, (float) $p2->tax_rate, 0.001, '0.15 means 15%');
        $this->assertEqualsWithDelta(500, (float) $p2->weight_grams, 0.01);
        $this->assertNull($p2->gtin, 'an invalid GTIN is emptied, the product still loads');
    }

    public function test_a_price_only_product_file_does_not_wipe_other_attributes(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'P1', 'name' => 'Milk', 'category' => 'Dairy',
            'supplier' => 'Al Ain', 'barcode' => '123', 'unit_cost' => 2, 'selling_price' => 3]);

        $this->load(Import::TYPE_PRODUCTS, ['SKU' => 'sku', 'Name' => 'name', 'Price' => 'selling_price'], [['P1', 'Milk', '3.5']]);

        $p = Product::where('sku', 'P1')->first();
        $this->assertEqualsWithDelta(3.5, (float) $p->selling_price, 0.001);
        $this->assertSame('Dairy', $p->category);
        $this->assertSame('Al Ain', $p->supplier);
        $this->assertSame('123', $p->barcode);
        $this->assertEqualsWithDelta(2, (float) $p->unit_cost, 0.001);
    }

    public function test_a_store_master_adopts_the_store_a_sales_file_created_from_its_code(): void
    {
        // A sales file identified the store by its code → auto-created store "ST042".
        $this->load(Import::TYPE_SALES, ['Date' => 'date', 'SKU' => 'sku', 'Store' => 'location', 'Qty' => 'quantity'], [['2026-04-03', 'S1', 'ST042', '1']]);
        $auto = Store::where('name', 'ST042')->firstOrFail();

        $this->load(Import::TYPE_STORES,
            ['Name' => 'name', 'Code' => 'code', 'Lat' => 'latitude', 'TZ' => 'timezone', 'Email' => 'email'],
            [['Fujairah Grocery 42', 'ST042', '200', 'Asia/Dubai', 'not-an-email']]);

        $this->assertSame(1, Store::where('tenant_id', $this->tenant->id)->count(), 'no duplicate store');
        $store = Store::find($auto->id);
        $this->assertSame('Fujairah Grocery 42', $store->name);
        $this->assertSame('ST042', $store->code);
        $this->assertNull($store->latitude, 'latitude 200 is out of range → empty');
        $this->assertSame('Asia/Dubai', $store->timezone);
        $this->assertNull($store->email);

        // Later sales by code, in any case/spacing, still land on it.
        $this->load(Import::TYPE_SALES, ['Date' => 'date', 'SKU' => 'sku', 'Store' => 'location', 'Qty' => 'quantity'], [['2026-04-04', 'S1', ' st042 ', '1']]);
        $this->assertSame([$auto->id], SalesTransaction::pluck('store_id')->unique()->values()->all());
    }

    public function test_store_names_match_regardless_of_case_and_spacing(): void
    {
        Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Downtown Mall']);
        $this->load(Import::TYPE_SALES, ['Date' => 'date', 'SKU' => 'sku', 'Store' => 'location', 'Qty' => 'quantity'], [['2026-04-03', 'S1', 'DOWNTOWN   mall', '1']]);

        $this->assertSame(1, Store::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_supplier_master_updates_partially_and_matches_names_loosely(): void
    {
        Supplier::create(['tenant_id' => $this->tenant->id, 'name' => 'Al Ain Farms', 'lead_time_days' => 5, 'contact_email' => 'a@b.test']);
        $this->load(Import::TYPE_SUPPLIERS, ['Name' => 'name', 'Terms' => 'payment_terms', 'Currency' => 'currency'], [['al ain farms', 'NET30', 'aed']]);

        $s = Supplier::where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame('NET30', $s->payment_terms);
        $this->assertSame('AED', $s->currency);
        $this->assertSame(5, (int) $s->lead_time_days);
        $this->assertSame('a@b.test', $s->contact_email);
    }

    public function test_the_new_fields_are_mapped_by_their_headers(): void
    {
        $inv = collect(app(ColumnMappingService::class)->map(['Safety Stock', 'Expiry Date', 'Batch No'], [], Import::TYPE_INVENTORY))->pluck('target_field', 'source_header');
        $this->assertSame('safety_stock', $inv['Safety Stock']);
        $this->assertSame('expiry_date', $inv['Expiry Date']);
        $this->assertSame('batch_ref', $inv['Batch No']);

        $sales = collect(app(ColumnMappingService::class)->map(['Channel', 'Promo Code'], [], Import::TYPE_SALES))->pluck('target_field', 'source_header');
        $this->assertSame('channel', $sales['Channel']);
        $this->assertSame('promotion_ref', $sales['Promo Code']);
    }
}
