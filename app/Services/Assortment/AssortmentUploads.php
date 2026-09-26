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
