<?php

namespace App\Services\Assortment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * v1.5 Phase 4 — the space a store × category shelf has, and what each product
 * on it takes. Space comes in levels; each works without the next:
 *
 *   count     no shelf metres: the optimiser counts products (Phase 2), and says so
 *   metres    shelf metres + product widths, facings unknown: each product is
 *             assumed to take the shelf's average number of facings, so adds and
 *             delists are fitted in metres; facings are not changed
 *   facings   shelf metres + widths + facings: exact metres used; facings of
 *             over-spaced products can be cut (never below their fewest) to make room
 *
 * A missing product width is the average of its subcategory (else of the
 * category) and is counted as assumed; a missing facing count is the shelf's
 * median. No width anywhere in the category = back to counting products.
 * Nothing is invented beyond that, and every assumption is stated.
 */
final class ShelfSpace
{
    public const BASIS_COUNT   = 'count';
    public const BASIS_METRES  = 'metres';
    public const BASIS_FACINGS = 'facings';

    /** A product whose share of the shelf is this many times its share of sales is over-spaced: a facing may be cut. */
    public const OVER_SPACED = 1.5;

    public const LABELS = [
        self::BASIS_COUNT   => 'Counted in products',
        self::BASIS_METRES  => 'Shelf metres',
        self::BASIS_FACINGS => 'Shelf metres and facings',
    ];

    public static function any(int $tenantId): bool
    {
        return DB::table('assortment_shelf_space')->where('tenant_id', $tenantId)->exists();
    }

    public static function formatSlug(?string $format): string
    {
        return Str::slug(trim((string) $format)) ?: 'unspecified';
    }

    /**
     * One store's shelf metres by category (its own rows, else its format's)
     * and the product space rows that apply to it.
     *
     * @return array{metres: array<string,array{0:float,1:string}>, rows: array<string,array<string,object>>}
     */
    public function forStore(int $tenantId, int $storeId): array
    {
        $format = self::formatSlug(DB::table('stores')->where('tenant_id', $tenantId)->where('id', $storeId)->value('format'));
        $metres = [];
        foreach (DB::table('assortment_shelf_space')->where('tenant_id', $tenantId)
            ->whereIn('scope_key', ['store:' . $storeId, 'format:' . $format])->get(['scope_key', 'category', 'metres']) as $r) {
            $cat = trim((string) $r->category);
            $own = str_starts_with((string) $r->scope_key, 'store:');
            if ($own || ! isset($metres[$cat])) {
                $metres[$cat] = [(float) $r->metres, $own ? 'store' : 'format'];
            }
        }
        $rows = [];
        if ($metres !== []) {
            foreach (DB::table('assortment_product_space')->where('tenant_id', $tenantId)->whereIn('store_key', [0, $storeId])
                ->get(['store_key', 'sku', 'width_cm', 'facings', 'min_facings', 'max_facings']) as $r) {
                $rows[(string) $r->sku][(int) $r->store_key === 0 ? 'chain' : 'store'] = $r;
            }
        }

        return ['metres' => $metres, 'rows' => $rows];
    }

    /**
     * The shelf with its space: `space` (basis, metres, metres used, what an
     * added product would take, the assumptions made) and, per product on it,
     * width (m), facings and fewest facings.
     *
     * @param  array<int,string>  $entrySkus  products that may be added
     */
    public static function apply(array $shelf, string $category, array $store, array $entrySkus, array $products): array
    {
        [$metres, $source] = $store['metres'][$category] ?? [null, null];
        if ($metres === null || $metres <= 0) {
            $shelf['space'] = ['basis' => self::BASIS_COUNT, 'note' => 'No shelf metres for this store and category: the range is counted in products.'];

            return $shelf;
        }
        $rows = $store['rows'];
        $field = function (string $sku, string $f) use ($rows) {
            $v = $rows[$sku]['store']->{$f} ?? null;

            return $v ?? ($rows[$sku]['chain']->{$f} ?? null);
        };

        $onShelf = array_map('strval', array_keys($shelf['skus']));
        $known = $bySub = [];
        foreach (array_unique(array_merge($onShelf, array_map('strval', $entrySkus))) as $sku) {
            $w = (float) $field($sku, 'width_cm');
            if ($w > 0) {
                $known[$sku] = $w / 100;
                $bySub[(string) ($products[$sku]['subcategory'] ?? '')][] = $w / 100;
            }
        }
        if ($known === []) {
            $shelf['space'] = ['basis' => self::BASIS_COUNT, 'metres' => $metres,
                'note' => 'Shelf metres are known, but no product in this category has a width: the range is counted in products.'];

            return $shelf;
        }
        $all = array_merge(...array_values($bySub));
        $avgAll = array_sum($all) / count($all);
        $width = function (string $sku) use ($known, $bySub, $avgAll, $products): array {
            if (isset($known[$sku])) {
                return [$known[$sku], false];
            }
            $sub = $bySub[(string) ($products[$sku]['subcategory'] ?? '')] ?? null;

            return [$sub ? array_sum($sub) / count($sub) : $avgAll, true];
        };

        $facings = [];
        foreach ($onShelf as $sku) {
            $f = (int) $field($sku, 'facings');
            if ($f > 0) {
                $facings[$sku] = $f;
            }
        }
        $basis = $facings !== [] ? self::BASIS_FACINGS : self::BASIS_METRES;
        $median = $facings !== [] ? self::median(array_values($facings)) : null;

        $widthsAssumed = $facingsAssumed = 0;
        $sumW = 0.0;
        foreach ($onShelf as $sku) {
            [$w, $assumed] = $width($sku);
            $shelf['skus'][$sku]['width'] = $w;
            $shelf['skus'][$sku]['width_assumed'] = $assumed;
            $widthsAssumed += $assumed ? 1 : 0;
            $sumW += $w;
        }
        // Metres only: each product is taken to use the shelf's average facings (the shelf is as full as its products fill it).
        $k = $basis === self::BASIS_METRES ? max(1.0, $metres / max(1e-9, $sumW)) : null;

        $used = 0.0;
        $subFacings = [];
        foreach ($onShelf as $sku) {
            if ($basis === self::BASIS_FACINGS) {
                $f = $facings[$sku] ?? null;
                $assumedF = $f === null;
                $f ??= (int) $median;
                $facingsAssumed += $assumedF ? 1 : 0;
                $min = max(1, (int) ($field($sku, 'min_facings') ?? 1));
                $shelf['skus'][$sku] += ['facings' => $f, 'min_facings' => min($min, $f), 'max_facings' => $field($sku, 'max_facings') ? (int) $field($sku, 'max_facings') : null,
                    'facings_assumed' => $assumedF];
                $subFacings[(string) ($products[$sku]['subcategory'] ?? '')][] = $f;
            } else {
                $f = $k;
                $shelf['skus'][$sku] += ['facings' => $k, 'min_facings' => $k, 'max_facings' => null, 'facings_assumed' => true];
            }
            $used += $shelf['skus'][$sku]['width'] * $f;
        }

        // What an added product would take: its own facings from the file, else what similar products have on this shelf.
        $entry = [];
        foreach (array_map('strval', $entrySkus) as $sku) {
            if (isset($shelf['skus'][$sku])) {
                continue;
            }
            [$w, $assumed] = $width($sku);
            if ($basis === self::BASIS_FACINGS) {
                $f = (int) $field($sku, 'facings');
                $sub = $subFacings[(string) ($products[$sku]['subcategory'] ?? '')] ?? null;
                $f = $f > 0 ? $f : (int) ($sub ? self::median($sub) : $median);
                $f = max((int) ($field($sku, 'min_facings') ?? 1), $f);
            } else {
                $f = $k;
            }
            $entry[$sku] = ['width' => $w, 'facings' => $f, 'width_assumed' => $assumed];
        }

        $notes = [];
        if ($widthsAssumed) {
            $notes[] = "{$widthsAssumed} product width(s) assumed from their subcategory's average";
        }
        if ($basis === self::BASIS_METRES) {
            $notes[] = 'facings not supplied: each product is taken at the shelf\'s average of ' . number_format($k, 1) . ' facings';
        } elseif ($facingsAssumed) {
            $notes[] = "{$facingsAssumed} product(s) without facings taken at the shelf's median of {$median}";
        }

        $shelf['space'] = [
            'basis'           => $basis,
            'metres'          => round($metres, 2),
            'metres_source'   => $source,
            'used'            => round($used, 4),
            'avg_facings'     => $k,
            'widths_assumed'  => $widthsAssumed,
            'facings_assumed' => $facingsAssumed,
            'entry'           => $entry,
            'note'            => $notes === [] ? null : ucfirst(implode('; ', $notes)) . '.',
        ];

        return $shelf;
    }

    private static function median(array $v): float
    {
        sort($v);
        $n = count($v);

        return $n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }
}
