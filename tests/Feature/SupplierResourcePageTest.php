<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Regression guard for the Suppliers list 500.
 *
 * The resource once used `TextColumn::counts('products')`, which resolves to a
 * correlated subquery on `products.supplier_id` — a column that does NOT exist
 * (products only carry a legacy free-text `supplier` string). A supplier's
 * products are derived through its purchase orders instead. The plain SmokeTest
 * boots the page with an empty table; this test seeds real rows so the Products
 * column is actually computed, which is what surfaced in production.
 */
class SupplierResourcePageTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->createTenant();
        $this->actingAsTenantAdmin($this->tenant);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);
    }

    public function test_suppliers_index_renders_with_data(): void
    {
        $supplier = Supplier::create([
            'tenant_id'      => $this->tenant->id,
            'code'           => 'SUP-1',
            'name'           => 'Acme Foods',
            'lead_time_days' => 5,
            'contact_email'  => 'orders@acme.test',
        ]);

        $p1 = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'P1', 'name' => 'Milk']);
        $p2 = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'P2', 'name' => 'Cheese']);

        // Two POs across two distinct products, plus a repeat of P1 to prove the
        // Products count is DISTINCT (should be 2, not 3).
        foreach ([[$p1, 'PO-1'], [$p2, 'PO-2'], [$p1, 'PO-3']] as [$product, $poNumber]) {
            PurchaseOrder::create([
                'tenant_id'   => $this->tenant->id,
                'product_id'  => $product->id,
                'supplier_id' => $supplier->id,
                'supplier'    => $supplier->name,
                'po_number'   => $poNumber,
                'sku'         => $product->sku,
                'qty_ordered' => 10,
                'order_date'  => now()->toDateString(),
            ]);
        }

        // Distinct products supplied = 2 (P1, P2), not 3.
        $this->assertSame(2, $supplier->purchaseOrders()->distinct()->count('product_id'));

        $url    = \App\Filament\Resources\SupplierResource::getUrl('index', ['tenant' => $this->tenant]);
        $status = $this->get($url)->baseResponse->getStatusCode();

        $this->assertLessThan(500, $status, "Suppliers index returned HTTP $status at $url");
    }
}
