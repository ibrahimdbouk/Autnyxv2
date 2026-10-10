<?php

namespace App\Services\Assortment;

use App\Models\AssortmentMustStock;
use App\Models\AssortmentStoreRange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * A6 — the two files a tenant can give the Assortment app (CSV or Excel):
 *
 *   RANGE FILE   store, sku, carried (Y/N; blank = Y). What each store is
 *                authorised to carry. Each row overrides what sales and stock
 *                suggest for that store × product: N = not carried (so it can be
 *                proposed as an add), Y = carried even without recent sales or
 *                stock. Uploading a store again replaces that store's file rows;
 *                products not in the file keep the inferred answer.
 *   MUST-STOCK   sku, store (blank = every store), reason. Never proposed for delisting.
 *   SHELF METRES (Phase 4) store or format, category, metres. A store's own
 *                row wins over its format's. A row replaces the same store (or
 *                format) × category.
 *   FACINGS      (Phase 4) sku, store (blank = every store), width (cm of one
 *                facing), facings, min facings, max facings. A row replaces that
 *                product's row for that store (or every store).
 *
 * Stores match by code or name, ignoring case and spacing. Unknown stores and
 * products are reported, not guessed.
 */
class AssortmentUploads
{
    /** @return array{rows:int, stores:int, carried:int, not_carried:int, skipped:array<int,string>} */
    public function importRange(int $tenantId, string $path): array
    {
        [$rows, $skipped] = $this->read($path, ['store', 'sku'], ['carried']);
        $stores = $this->storeLookup($tenantId);
        $products = DB::table('products')->where('tenant_id', $tenantId)->pluck('id', 'sku');

        $byStore = [];
        foreach ($rows as $line => $r) {
            $storeId = $stores[$this->key($r['store'])] ?? null;
            if ($storeId === null) {
                $skipped[] = "Row {$line}: store \"{$r['store']}\" not found";

                continue;
            }
            $sku = trim((string) $r['sku']);
            if ($sku === '') {
                $skipped[] = "Row {$line}: no SKU";

                continue;
            }
            $flag = strtolower(trim((string) ($r['carried'] ?? '')));
            $byStore[$storeId][$sku] = ! in_array($flag, ['n', 'no', '0', 'false', 'not carried', 'delisted'], true);
        }

        $carried = $not = 0;
        DB::transaction(function () use ($tenantId, $byStore, $products, &$carried, &$not) {
            $today = now()->toDateString();
            foreach ($byStore as $storeId => $skus) {
                DB::table('assortment_store_ranges')->where('tenant_id', $tenantId)->where('store_id', $storeId)
                    ->where('source', AssortmentStoreRange::SOURCE_LISTING)->delete();
                $existing = DB::table('assortment_store_ranges')->where('tenant_id', $tenantId)->where('store_id', $storeId)
                    ->whereIn('sku', array_map('strval', array_keys($skus)))->get()->keyBy('sku');
                foreach (array_chunk($skus, 500, true) as $chunk) {
                    $rows = [];
                    foreach ($chunk as $sku => $isCarried) {
                        $prev = $existing[$sku] ?? null;
                        $rows[] = [
                            'tenant_id' => $tenantId, 'store_id' => $storeId, 'sku' => (string) $sku,
                            'product_id' => $products[$sku] ?? null, 'carried' => $isCarried,
                            'source' => AssortmentStoreRange::SOURCE_LISTING,
                            'first_seen' => $prev->first_seen ?? ($isCarried ? $today : null),
                            'last_seen' => $prev->last_seen ?? null, 'last_sale' => $prev->last_sale ?? null,
                            'last_in_stock' => $prev->last_in_stock ?? null,
                            'as_of_date' => $prev->as_of_date ?? $today, 'created_at' => now(), 'updated_at' => now(),
                        ];
                        $isCarried ? $carried++ : $not++;
                    }
                    DB::table('assortment_store_ranges')->upsert($rows, ['tenant_id', 'store_id', 'sku'],
                        ['product_id', 'carried', 'source', 'first_seen', 'updated_at']);
                }
            }
        });

        return ['rows' => $carried + $not, 'stores' => count($byStore), 'carried' => $carried, 'not_carried' => $not, 'skipped' => $skipped];
    }

    /** Put the range back to what sales and stock show (the next run rebuilds it). */
    public function clearRange(int $tenantId): int
    {
        return DB::table('assortment_store_ranges')->where('tenant_id', $tenantId)
            ->where('source', AssortmentStoreRange::SOURCE_LISTING)->delete();
    }

    /** @return array{added:int, skipped:array<int,string>} */
    public function importMustStock(int $tenantId, string $path): array
    {
        [$rows, $skipped] = $this->read($path, ['sku'], ['store', 'reason']);
        $stores = $this->storeLookup($tenantId);
        $known = DB::table('products')->where('tenant_id', $tenantId)->pluck('sku')->flip();

        $added = 0;
        foreach ($rows as $line => $r) {
            $sku = trim((string) $r['sku']);
            if ($sku === '' || ! isset($known[$sku])) {
                $skipped[] = "Row {$line}: product \"{$sku}\" not found";

                continue;
            }
            $storeId = null;
            if (trim((string) ($r['store'] ?? '')) !== '') {
                $storeId = $stores[$this->key($r['store'])] ?? null;
                if ($storeId === null) {
                    $skipped[] = "Row {$line}: store \"{$r['store']}\" not found";

                    continue;
                }
            }
            $this->addMustStock($tenantId, $sku, $storeId, $r['reason'] ?? null);
            $added++;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    public function addMustStock(int $tenantId, string $sku, ?int $storeId, ?string $reason): AssortmentMustStock
    {
        return AssortmentMustStock::updateOrCreate(
            ['tenant_id' => $tenantId, 'sku' => trim($sku), 'store_id' => $storeId],
            ['reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 160) : null],
        );
    }

    /** @return array{rows:int, stores:int, formats:int, skipped:array<int,string>} */
    public function importShelfSpace(int $tenantId, string $path): array
    {
        [$rows, $skipped] = $this->read($path, ['category', 'metres'], ['store', 'format']);
        $stores = $this->storeLookup($tenantId);
        $formats = DB::table('stores')->where('tenant_id', $tenantId)->pluck('format')
            ->map(fn ($f) => ShelfSpace::formatSlug($f))->unique()->flip();
        $categories = DB::table('products')->where('tenant_id', $tenantId)->whereNotNull('category')
            ->selectRaw('DISTINCT TRIM(category) AS c')->pluck('c')->mapWithKeys(fn ($c) => [mb_strtolower((string) $c) => (string) $c]);

        $out = [];
        foreach ($rows as $line => $r) {
            $cat = $categories[mb_strtolower(trim((string) $r['category']))] ?? null;
            if ($cat === null) {
                $skipped[] = "Row {$line}: category \"{$r['category']}\" not found on the product file";

                continue;
            }
            $m = $this->number($r['metres']);
            if ($m === null || $m <= 0 || $m > 10000) {
                $skipped[] = "Row {$line}: metres \"{$r['metres']}\" is not a length in metres";

                continue;
            }
            $storeId = null;
            $format = null;
            if (trim((string) ($r['store'] ?? '')) !== '') {
                $storeId = $stores[$this->key($r['store'])] ?? null;
                if ($storeId === null) {
                    $skipped[] = "Row {$line}: store \"{$r['store']}\" not found";

                    continue;
                }
            } elseif (trim((string) ($r['format'] ?? '')) !== '') {
                $format = ShelfSpace::formatSlug((string) $r['format']);
                if (! isset($formats[$format])) {
                    $skipped[] = "Row {$line}: no store has the format \"{$r['format']}\"";

                    continue;
                }
            } else {
                $skipped[] = "Row {$line}: give a store or a store format";

                continue;
            }
            $scope = $storeId !== null ? 'store:' . $storeId : 'format:' . $format;
            $out[$scope . '|' . $cat] = ['tenant_id' => $tenantId, 'store_id' => $storeId, 'format' => $format, 'scope_key' => $scope,
                'category' => mb_substr($cat, 0, 191), 'metres' => round($m, 2), 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk(array_values($out), 500) as $chunk) {
            DB::table('assortment_shelf_space')->upsert($chunk, ['tenant_id', 'scope_key', 'category'], ['metres', 'store_id', 'format', 'updated_at']);
        }

        return ['rows' => count($out), 'stores' => collect($out)->whereNotNull('store_id')->pluck('store_id')->unique()->count(),
            'formats' => collect($out)->whereNotNull('format')->pluck('format')->unique()->count(), 'skipped' => $skipped];
    }

    /** @return array{rows:int, widths:int, facings:int, skipped:array<int,string>} */
    public function importProductSpace(int $tenantId, string $path): array
    {
        [$rows, $skipped] = $this->read($path, ['sku'], ['store', 'width', 'facings', 'min_facings', 'max_facings']);
        $stores = $this->storeLookup($tenantId);
        $known = DB::table('products')->where('tenant_id', $tenantId)->pluck('sku')->flip();

        $out = [];
        foreach ($rows as $line => $r) {
            $sku = trim((string) $r['sku']);
            if ($sku === '' || ! isset($known[$sku])) {
                $skipped[] = "Row {$line}: product \"{$sku}\" not found";

                continue;
            }
            $storeId = null;
            if (trim((string) ($r['store'] ?? '')) !== '') {
                $storeId = $stores[$this->key($r['store'])] ?? null;
                if ($storeId === null) {
                    $skipped[] = "Row {$line}: store \"{$r['store']}\" not found";

                    continue;
                }
            }
            $w = $this->number($r['width'] ?? null);
            $f = $this->number($r['facings'] ?? null);
            $min = $this->number($r['min_facings'] ?? null);
            $max = $this->number($r['max_facings'] ?? null);
            if ($w === null && $f === null && $min === null && $max === null) {
                $skipped[] = "Row {$line}: no width or facings";

                continue;
            }
            if (($w !== null && ($w <= 0 || $w > 1000)) || ($f !== null && ($f < 1 || $f > 500))) {
                $skipped[] = "Row {$line}: width must be in cm (0–1000) and facings 1–500";

                continue;
            }
            if ($min !== null && (($f !== null && $min > $f) || ($max !== null && $min > $max))) {
                $skipped[] = "Row {$line}: fewest facings is more than the current or the most";

                continue;
            }
            $out[($storeId ?? 0) . '|' . $sku] = ['tenant_id' => $tenantId, 'store_id' => $storeId, 'store_key' => $storeId ?? 0, 'sku' => $sku,
                'width_cm' => $w !== null ? round($w, 2) : null, 'facings' => $f !== null ? (int) round($f) : null,
                'min_facings' => $min !== null ? max(1, (int) round($min)) : null, 'max_facings' => $max !== null ? (int) round($max) : null,
                'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk(array_values($out), 1000) as $chunk) {
            DB::table('assortment_product_space')->upsert($chunk, ['tenant_id', 'store_key', 'sku'],
                ['store_id', 'width_cm', 'facings', 'min_facings', 'max_facings', 'updated_at']);
        }

        return ['rows' => count($out), 'widths' => collect($out)->whereNotNull('width_cm')->count(),
            'facings' => collect($out)->whereNotNull('facings')->count(), 'skipped' => $skipped];
    }

    /** Back to counting products: shelf metres and product space removed (the next run uses the count). */
    public function clearSpace(int $tenantId): int
    {
        return DB::table('assortment_shelf_space')->where('tenant_id', $tenantId)->delete()
            + DB::table('assortment_product_space')->where('tenant_id', $tenantId)->delete();
    }

    private function number(mixed $v): ?float
    {
        if ($v === null || trim((string) $v) === '') {
            return null;
        }
        $t = str_replace([' ', ','], ['', '.'], trim((string) $v));

        return is_numeric($t) ? (float) $t : -1.0;
    }

    // ── File reading ──────────────────────────────────────────────────────────

    /**
     * Rows keyed by spreadsheet line, with the wanted columns found by header.
     *
     * @return array{0: array<int,array<string,mixed>>, 1: array<int,string>}
     */
    private function read(string $path, array $required, array $optional): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        if ($sheet === []) {
            throw new \InvalidArgumentException('The file is empty.');
        }
        $aliases = [
            'store'   => ['store', 'store code', 'store_code', 'store name', 'store_name', 'site', 'location', 'branch'],
            'sku'     => ['sku', 'item', 'item code', 'item_code', 'product', 'product code', 'product_code', 'article', 'barcode'],
            'carried' => ['carried', 'listed', 'ranged', 'in range', 'status', 'active'],
            'reason'  => ['reason', 'why', 'note', 'notes'],
            'format'  => ['format', 'store format', 'store_format', 'store type', 'store_type'],
            'category' => ['category', 'category name', 'category_name', 'department', 'section'],
            'metres'  => ['metres', 'meters', 'shelf metres', 'shelf meters', 'shelf_metres', 'shelf_meters', 'linear metres', 'linear meters',
                'linear_metres', 'linear_meters', 'metres (m)', 'meters (m)', 'length (m)', 'length m', 'm'],
            'width'   => ['width', 'width cm', 'width_cm', 'width (cm)', 'facing width', 'facing_width', 'product width', 'product_width'],
            'facings' => ['facings', 'current facings', 'current_facings', 'faces', 'facing'],
            'min_facings' => ['min facings', 'min_facings', 'minimum facings', 'minimum_facings', 'fewest facings', 'min'],
            'max_facings' => ['max facings', 'max_facings', 'maximum facings', 'maximum_facings', 'most facings', 'max'],
        ];
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), (array) array_shift($sheet));
        $cols = [];
        foreach (array_merge($required, $optional) as $want) {
            foreach ($header as $i => $h) {
                if (in_array($h, $aliases[$want], true)) {
                    $cols[$want] = $i;
                    break;
                }
            }
        }
        $missing = array_diff($required, array_keys($cols));
        if ($missing !== []) {
            throw new \InvalidArgumentException('Missing column(s): ' . implode(', ', $missing) . '. The first row must name the columns.');
        }

        $rows = [];
        foreach ($sheet as $n => $line) {
            $row = [];
            foreach ($cols as $name => $i) {
                $row[$name] = $line[$i] ?? null;
            }
            if (implode('', array_map('strval', $row)) === '') {
                continue;
            }
            $rows[$n + 2] = $row;
        }

        return [$rows, []];
    }

    /** @return array<string,int> normalised code/name => store id */
    private function storeLookup(int $tenantId): array
    {
        $out = [];
        foreach (DB::table('stores')->where('tenant_id', $tenantId)->get(['id', 'code', 'name']) as $s) {
            if ($s->name) {
                $out[$this->key($s->name)] = (int) $s->id;
            }
            if ($s->code) {
                $out[$this->key($s->code)] = (int) $s->id;
            }
        }

        return $out;
    }

    private function key(mixed $v): string
    {
        return Str::of((string) $v)->lower()->replaceMatches('/\s+/', ' ')->trim()->toString();
    }
}
