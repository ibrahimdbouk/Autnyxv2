<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the Product hierarchy from the flat products table:
 *   - one `category` node per distinct non-null category (per tenant)
 *   - one `subcategory` node per distinct (category, subcategory), under its category
 *   - one `product` leaf per product, parented under its subcategory (else category, else root)
 *
 * Two passes so leaves can be batch-inserted. Skips a tenant that already has
 * product_nodes, so re-running does not duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        $products = DB::table('products')->get();

        foreach ($products->groupBy('tenant_id') as $tenantId => $tenantProducts) {
            if (DB::table('product_nodes')->where('tenant_id', $tenantId)->exists()) {
                continue;
            }

            // Pass 1a — category tier.
            $categoryNode = [];
            foreach ($tenantProducts->pluck('category')->filter()->unique() as $category) {
                $categoryNode[$category] = DB::table('product_nodes')->insertGetId([
                    'tenant_id'  => $tenantId,
                    'type'       => 'category',
                    'name'       => $category,
                    'parent_id'  => null,
                    'product_id' => null,
                    'attributes' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Pass 1b — subcategory tier (only where both category + subcategory exist).
            $subcategoryNode = [];
            $subPairs = $tenantProducts
                ->filter(fn ($p) => ! empty($p->category) && ! empty($p->subcategory))
                ->map(fn ($p) => ['category' => $p->category, 'subcategory' => $p->subcategory])
                ->unique(fn ($x) => $x['category'] . '|' . $x['subcategory']);

            foreach ($subPairs as $pair) {
                $key = $pair['category'] . '|' . $pair['subcategory'];
                $subcategoryNode[$key] = DB::table('product_nodes')->insertGetId([
                    'tenant_id'  => $tenantId,
                    'type'       => 'subcategory',
                    'name'       => $pair['subcategory'],
                    'parent_id'  => $categoryNode[$pair['category']] ?? null,
                    'product_id' => null,
                    'attributes' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Pass 2 — product leaves, batched.
            $leaves = [];
            foreach ($tenantProducts as $p) {
                $parentId = null;
                if (! empty($p->category) && ! empty($p->subcategory)
                    && isset($subcategoryNode[$p->category . '|' . $p->subcategory])) {
                    $parentId = $subcategoryNode[$p->category . '|' . $p->subcategory];
                } elseif (! empty($p->category) && isset($categoryNode[$p->category])) {
                    $parentId = $categoryNode[$p->category];
                }

                $leaves[] = [
                    'tenant_id'  => $tenantId,
                    'type'       => 'product',
                    'code'       => $p->sku ?? null,
                    'name'       => $p->name,
                    'parent_id'  => $parentId,
                    'product_id' => $p->id,
                    'attributes' => json_encode(array_filter([
                        'brand'     => $p->brand ?? null,
                        'pack_size' => $p->pack_size ?? null,
                    ])),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($leaves) >= 500) {
                    DB::table('product_nodes')->insert($leaves);
                    $leaves = [];
                }
            }

            if ($leaves !== []) {
                DB::table('product_nodes')->insert($leaves);
            }
        }
    }

    public function down(): void
    {
        DB::table('product_nodes')->truncate();
    }
};
