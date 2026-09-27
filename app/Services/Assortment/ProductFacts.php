<?php

namespace App\Services\Assortment;

use Illuminate\Support\Facades\DB;

/**
 * The product file as Assortment reads it: sku => name, category, subcategory,
 * brand, pack size, price and cost (what similarity and margins are judged by).
 * The whole file for a run; one category for the Decision Studio.
 */
final class ProductFacts
{
    /** @return array<string,array<string,mixed>> sku => facts */
    public static function load(int $tenantId, ?string $category = null): array
    {
        $q = DB::table('products')->where('tenant_id', $tenantId);
        if ($category !== null) {
            $q->whereRaw('TRIM(category) = ?', [$category]);
        }
        $out = [];
        foreach ($q->get(['id', 'sku', 'name', 'category', 'subcategory', 'brand', 'pack_size', 'selling_price', 'unit_cost', 'status', 'season']) as $p) {
            $cat = trim((string) $p->category);
            $sub = trim((string) $p->subcategory);
            $out[(string) $p->sku] = [
                'id'          => (int) $p->id,
                'name'        => (string) ($p->name ?? ''),
                'category'    => $cat !== '' ? $cat : null,
                'subcategory' => $sub !== '' ? $sub : null,
                'brand'       => trim((string) $p->brand) ?: null,
                'pack_size'   => trim((string) $p->pack_size) ?: null,
                'price'       => (float) ($p->selling_price ?? 0),
                'cost'        => (float) ($p->unit_cost ?? 0),
                'status'      => $p->status,
                'season'      => $p->season,
            ];
        }

        return $out;
    }
}
