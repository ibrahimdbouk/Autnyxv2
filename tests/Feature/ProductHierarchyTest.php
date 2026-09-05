<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductNode;
use Tests\TestCase;

/**
 * P1.1 — the canonical Product hierarchy: roll-up (product ids under a node) and
 * ancestry work across category → subcategory → product.
 */
class ProductHierarchyTest extends TestCase
{
    public function test_category_rolls_up_across_subcategories_to_products(): void
    {
        $tenant = $this->createTenant();

        $category = ProductNode::create(['tenant_id' => $tenant->id, 'type' => ProductNode::TYPE_CATEGORY, 'name' => 'Beverages']);
        $sub      = ProductNode::create(['tenant_id' => $tenant->id, 'type' => ProductNode::TYPE_SUBCATEGORY, 'name' => 'Soft Drinks', 'parent_id' => $category->id]);

        $p1 = Product::create(['tenant_id' => $tenant->id, 'sku' => 'SKU-1', 'name' => 'Cola', 'category' => 'Beverages', 'subcategory' => 'Soft Drinks']);
        $p2 = Product::create(['tenant_id' => $tenant->id, 'sku' => 'SKU-2', 'name' => 'Lemonade', 'category' => 'Beverages', 'subcategory' => 'Soft Drinks']);
        // A product directly under the category (no subcategory).
        $p3 = Product::create(['tenant_id' => $tenant->id, 'sku' => 'SKU-3', 'name' => 'Water', 'category' => 'Beverages']);

        ProductNode::create(['tenant_id' => $tenant->id, 'type' => ProductNode::TYPE_PRODUCT, 'name' => 'Cola', 'code' => 'SKU-1', 'parent_id' => $sub->id, 'product_id' => $p1->id]);
        ProductNode::create(['tenant_id' => $tenant->id, 'type' => ProductNode::TYPE_PRODUCT, 'name' => 'Lemonade', 'code' => 'SKU-2', 'parent_id' => $sub->id, 'product_id' => $p2->id]);
        $leaf3 = ProductNode::create(['tenant_id' => $tenant->id, 'type' => ProductNode::TYPE_PRODUCT, 'name' => 'Water', 'code' => 'SKU-3', 'parent_id' => $category->id, 'product_id' => $p3->id]);

        // Category rolls up to all three products (across the subcategory and direct).
        $this->assertEqualsCanonicalizing([$p1->id, $p2->id, $p3->id], $category->productIds());
        // Subcategory rolls up to just its two.
        $this->assertEqualsCanonicalizing([$p1->id, $p2->id], $sub->productIds());

        // Ancestry of the direct-under-category leaf is just the category.
        $this->assertSame([$category->id], $leaf3->ancestors()->pluck('id')->all());

        // product → canonical node link.
        $this->assertSame($leaf3->id, Product::find($p3->id)->productNode->id);
    }
}
