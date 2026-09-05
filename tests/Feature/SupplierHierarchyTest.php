<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierNode;
use Tests\TestCase;

/**
 * P1.1 — the canonical Supplier hierarchy: a group rolls up to its suppliers,
 * and a leaf links back to its operational supplier row.
 */
class SupplierHierarchyTest extends TestCase
{
    public function test_group_rolls_up_to_its_suppliers(): void
    {
        $tenant = $this->createTenant();

        $group = SupplierNode::create(['tenant_id' => $tenant->id, 'type' => SupplierNode::TYPE_GROUP, 'name' => 'Dairy Distributors']);

        $s1 = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Acme Dairy', 'type' => 'Dairy Distributors']);
        $s2 = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Fresh Farms', 'type' => 'Dairy Distributors']);
        // A supplier with no group.
        $s3 = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Solo Vendor']);

        $n1 = SupplierNode::create(['tenant_id' => $tenant->id, 'type' => SupplierNode::TYPE_SUPPLIER, 'name' => 'Acme Dairy', 'parent_id' => $group->id, 'supplier_id' => $s1->id]);
        SupplierNode::create(['tenant_id' => $tenant->id, 'type' => SupplierNode::TYPE_SUPPLIER, 'name' => 'Fresh Farms', 'parent_id' => $group->id, 'supplier_id' => $s2->id]);
        $n3 = SupplierNode::create(['tenant_id' => $tenant->id, 'type' => SupplierNode::TYPE_SUPPLIER, 'name' => 'Solo Vendor', 'parent_id' => null, 'supplier_id' => $s3->id]);

        $this->assertEqualsCanonicalizing([$s1->id, $s2->id], $group->supplierIds());
        $this->assertSame([$s1->id], $n1->supplierIds(), 'a leaf rolls up to just itself');
        $this->assertSame([], $n3->ancestors()->all(), 'an ungrouped supplier has no ancestors');

        // supplier → canonical node link.
        $this->assertSame($n1->id, Supplier::find($s1->id)->supplierNode->id);
    }
}
