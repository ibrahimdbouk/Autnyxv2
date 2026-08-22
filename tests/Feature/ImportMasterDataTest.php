<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\ImportRow;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Import\CanonicalSchema;
use App\Services\Import\ImportProcessorService;
use Tests\TestCase;

class ImportMasterDataTest extends TestCase
{
    private function makeImport(string $type): Import
    {
        return Import::create([
            'tenant_id'         => $this->tenant->id,
            'original_filename' => 'test.csv',
            'disk'              => 'local',
            'path'              => 'test.csv',
            'data_type'         => $type,
            'status'            => Import::STATUS_IMPORTING,
        ]);
    }

    private \App\Models\Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
    }

    private function runRow(Import $import, array $mapped): void
    {
        $row = ImportRow::create([
            'import_id'   => $import->id,
            'tenant_id'   => $this->tenant->id,
            'row_number'  => 2,
            'raw_data'    => $mapped,   // required (NOT NULL); real imports store the original source row here
            'mapped_data' => $mapped,
            'status'      => ImportRow::STATUS_PENDING,
        ]);

        app(ImportProcessorService::class)->retryRows($import, collect([$row]));
    }

    public function test_new_types_are_registered(): void
    {
        $labels = Import::dataTypeLabels();
        $this->assertArrayHasKey('stores', $labels);
        $this->assertArrayHasKey('suppliers', $labels);
        $this->assertArrayHasKey('users', $labels);

        $this->assertArrayHasKey('region', CanonicalSchema::forType('stores'));
        $this->assertArrayHasKey('lead_time_days', CanonicalSchema::forType('suppliers'));
        $this->assertArrayHasKey('email', CanonicalSchema::forType('users'));
    }

    public function test_store_import_creates_enriched_store(): void
    {
        $import = $this->makeImport(Import::TYPE_STORES);
        $this->runRow($import, ['name' => 'Downtown', 'code' => 'S01', 'city' => 'New York', 'region' => 'Northeast', 'country' => 'USA']);

        $store = Store::where('tenant_id', $this->tenant->id)->where('name', 'Downtown')->first();
        $this->assertNotNull($store);
        $this->assertSame('Northeast', $store->region);
        $this->assertSame('S01', $store->code);
    }

    public function test_supplier_import_creates_supplier_with_lead_time(): void
    {
        $import = $this->makeImport(Import::TYPE_SUPPLIERS);
        $this->runRow($import, ['name' => 'Acme Co', 'code' => 'V12', 'lead_time_days' => '14', 'contact_email' => 'sales@acme.test']);

        $supplier = Supplier::where('tenant_id', $this->tenant->id)->where('name', 'Acme Co')->first();
        $this->assertNotNull($supplier);
        $this->assertSame(14, (int) $supplier->lead_time_days);
        $this->assertSame('sales@acme.test', $supplier->contact_email);
    }

    public function test_user_import_creates_tenant_scoped_user_with_role(): void
    {
        $import = $this->makeImport(Import::TYPE_USERS);
        $this->runRow($import, ['name' => 'Jane Ops', 'email' => 'JANE@Store.test', 'role' => 'tenant_admin']);

        $user = User::where('email', 'jane@store.test')->first();
        $this->assertNotNull($user);
        $this->assertSame($this->tenant->id, $user->tenant_id);
        $this->assertTrue($user->is_tenant_admin);
        $this->assertFalse((bool) $user->is_super_admin);
    }

    public function test_purchase_order_import_links_supplier(): void
    {
        $import = $this->makeImport(Import::TYPE_PURCHASE_ORDERS);
        $this->runRow($import, [
            'po_number' => 'PO-1', 'supplier' => 'Globex', 'sku' => 'SKU-1',
            'qty_ordered' => '100', 'order_date' => '2026-08-01',
        ]);

        $po = PurchaseOrder::where('tenant_id', $this->tenant->id)->where('po_number', 'PO-1')->first();
        $this->assertNotNull($po);
        $this->assertNotNull($po->supplier_id);

        $supplier = Supplier::find($po->supplier_id);
        $this->assertSame('Globex', $supplier->name);
    }
}
