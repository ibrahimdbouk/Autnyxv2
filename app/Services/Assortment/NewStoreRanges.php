<?php

namespace App\Services\Assortment;

use App\Models\AssortmentNewRange;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * v2.3 — a starting range for a store with no history (a new opening), or a
 * remodel (a store whose shelves change), built from stores like it.
 *
 *   donors      stores of the same format in the same region that trade; too few
 *               (under `min_peer_group`) → every store of the format; under 3 → none
 *   per product how many donors carry it (core ≥ 80%, expected ≥ 50%) and what it
 *               sells a year at the typical donor that carries it (season-adjusted)
 *   the range   core first, then the expected range by sales — as many as the
 *               donors typically carry, or fitted to the metres given (product
 *               widths × facings from the shelf files)
 *   remodel     the store's own range is the start: what it carries stays unless
 *               the shelf is too short, products the donors expect are added, and
 *               what no longer fits is marked to go
 *
 * Every figure is Estimated (from other stores), as a low–high range across the
 * donors. A proposal only: nothing is changed. Once a new store has traded 12
 * weeks, its own Assortment run judges it instead.
 */
class NewStoreRanges
{
    public const CORE = 0.8;

    public const EXPECTED = 0.5;

    public const OWN_HISTORY_DAYS = 84;

    public function __construct(private RangeModel $ranges) {}

    /** @return array{stores: array<int,int>, basis: string, label: string} */
    public function donors(int $tenantId, ?string $format, ?string $region, ?int $exclude = null): array
    {
        $asOf = $this->ranges->asOfDate($tenantId) ?? now()->toDateString();
        $from = Carbon::parse($asOf)->subDays(89)->toDateString();
        $trading = DB::table('sales_daily')->where('tenant_id', $tenantId)->whereBetween('date', [$from, $asOf])
            ->distinct()->pluck('store_id')->map(fn ($id) => (int) $id)->flip();
        $stores = DB::table('stores')->where('tenant_id', $tenantId)->get(['id', 'format', 'region'])
            ->filter(fn ($s) => isset($trading[(int) $s->id]) && (int) $s->id !== (int) $exclude);
        $f = ShelfSpace::formatSlug($format);
        $sameFormat = $stores->filter(fn ($s) => ShelfSpace::formatSlug($s->format) === $f);
        $sameRegion = $region ? $sameFormat->filter(fn ($s) => Str::lower(trim((string) $s->region)) === Str::lower(trim($region))) : collect();
        $min = max(3, (int) config('assortment.min_peer_group', 5));

        if ($sameRegion->count() >= $min) {
            return ['stores' => $sameRegion->pluck('id')->map(fn ($id) => (int) $id)->values()->all(), 'basis' => 'format_region',
                'label' => Str::headline($f) . ' stores in ' . trim((string) $region)];
        }
        if ($sameFormat->count() >= 3) {
            return ['stores' => $sameFormat->pluck('id')->map(fn ($id) => (int) $id)->values()->all(), 'basis' => 'format',
                'label' => 'All ' . Str::headline($f) . ' stores' . ($region ? ' (too few in ' . trim($region) . ')' : '')];
        }

        return ['stores' => [], 'basis' => 'none', 'label' => 'Too few ' . Str::headline($f) . ' stores trading'];
    }

    /**
     * @param  array{kind:string, name?:?string, store_id?:?int, format?:?string, region?:?string, categories?:array<int,string>, metres?:array<string,float>}  $in
     */
    public function build(Tenant $tenant, User $by, array $in): AssortmentNewRange
    {
        $tid = (int) $tenant->id;
        $kind = $in['kind'] === AssortmentNewRange::REMODEL ? AssortmentNewRange::REMODEL : AssortmentNewRange::NEW;
        $store = ! empty($in['store_id']) ? DB::table('stores')->where('tenant_id', $tid)->where('id', (int) $in['store_id'])->first(['id', 'name', 'format', 'region']) : null;
        abort_if($kind === AssortmentNewRange::REMODEL && $store === null, 422, 'A remodel is for one of your stores: pick it.');
        $format = $store->format ?? ($in['format'] ?? null);
        $region = $store->region ?? ($in['region'] ?? null);
        abort_if(blank($format), 422, 'Pick the store format, so the range comes from stores like it.');

        $donors = $this->donors($tid, $format, $region, $store?->id);
        abort_if($donors['stores'] === [], 422, $donors['label'] . ': a starting range needs at least 3 similar stores that trade.');
        $asOf = $this->ranges->asOfDate($tid) ?? now()->toDateString();
        $products = ProductFacts::load($tid);
        $cats = array_values(array_filter(array_map('trim', (array) ($in['categories'] ?? []))));
        $metresIn = array_filter(array_map(fn ($m) => is_numeric($m) ? (float) $m : null, (array) ($in['metres'] ?? [])), fn ($m) => $m !== null && $m > 0);

        $facts = $this->donorFacts($tid, $donors['stores'], $asOf, $products, $cats);
        $own = $store ? DB::table('assortment_store_ranges')->where('tenant_id', $tid)->where('store_id', $store->id)->where('carried', true)->pluck('sku')->map(fn ($s) => (string) $s)->flip()->all() : [];
        $space = $this->space($tid, $store?->id, $format);
        $seasons = app(SeasonalIndex::class)->forTenant($tid, $asOf, 90);

        // v2.2 deals bind here too: the store's own, or (a store not yet listed) those for its format or every store.
        $active = CommercialConstraints::active($tid, $asOf);
        $f = ShelfSpace::formatSlug($format);
        $rules = $store ? (CommercialConstraints::byStore($tid, $active)[(int) $store->id] ?? [])
            : array_values(array_filter($active, fn ($c) => $c['store_id'] === null && ($c['format'] === null || $c['format'] === $f)));

        $result = [];
        foreach ($facts['categories'] as $cat => $c) {
            $result[$cat] = $this->category($cat, $c, $kind, $own, $metresIn[$cat] ?? ($space['metres'][$cat] ?? null),
                isset($metresIn[$cat]) ? 'asked' : (isset($space['metres'][$cat]) ? 'shelf file' : null), $space, $products, SeasonalIndex::factor($seasons, $cat), count($donors['stores']), $rules);
        }
        ksort($result);

        return AssortmentNewRange::create([
            'tenant_id' => $tid,
            'name' => trim((string) ($in['name'] ?? '')) ?: ($kind === AssortmentNewRange::REMODEL ? 'Remodel: ' . $store->name : 'New ' . Str::headline((string) $format) . ($region ? ' in ' . $region : '') . ($store ? ': ' . $store->name : '')),
            'kind' => $kind, 'store_id' => $store?->id, 'format' => ShelfSpace::formatSlug($format), 'region' => $region,
            'donors' => ['stores' => $donors['stores'], 'basis' => $donors['basis'], 'label' => $donors['label']],
            'metres' => $metresIn ?: null, 'result' => $result, 'as_of_date' => $asOf, 'created_by' => $by->id,
        ]);
    }

    /** Per category and product: carrying share and sales a year per carrying donor (p25/median/p75), and each donor's count. */
    private function donorFacts(int $tid, array $donors, string $asOf, array $products, array $cats): array
    {
        $from = Carbon::parse($asOf)->subDays(89)->toDateString();
        $in = implode(',', array_map('intval', $donors));
        $carried = DB::select("SELECT r.store_id, r.sku, TRIM(p.category) AS cat FROM assortment_store_ranges r
            JOIN products p ON p.tenant_id = r.tenant_id AND p.sku = r.sku
            WHERE r.tenant_id = ? AND r.store_id IN ({$in}) AND r.carried AND p.category IS NOT NULL AND TRIM(p.category) <> ''", [$tid]);
        $sales = [];
        foreach (DB::select("SELECT store_id, sku, SUM(CASE WHEN revenue > 0 THEN revenue ELSE 0 END) AS rev FROM sales_daily
            WHERE tenant_id = ? AND store_id IN ({$in}) AND date BETWEEN ? AND ? GROUP BY store_id, sku", [$tid, $from, $asOf]) as $r) {
            $sales[(int) $r->store_id . '|' . $r->sku] = (float) $r->rev * 365 / 90;
        }
        $out = [];
        $counts = [];
        foreach ($carried as $r) {
            $cat = (string) $r->cat;
            if ($cats !== [] && ! in_array($cat, $cats, true)) {
                continue;
            }
            $sku = (string) $r->sku;
            $out[$cat]['products'][$sku]['stores'][] = (int) $r->store_id;
            $out[$cat]['products'][$sku]['sales'][] = $sales[(int) $r->store_id . '|' . $sku] ?? 0.0;
            $counts[$cat][(int) $r->store_id] = ($counts[$cat][(int) $r->store_id] ?? 0) + 1;
        }
        foreach ($out as $cat => $c) {
            $out[$cat]['counts'] = array_values($counts[$cat] ?? []);
        }

        return ['categories' => $out];
    }

    /** Shelf metres for the store (its own rows, else its format's) and product widths × facings (chain rows). */
    private function space(int $tid, ?int $storeId, ?string $format): array
    {
        $out = ['metres' => [], 'width' => [], 'facings' => []];
        if (! ShelfSpace::any($tid) && ! DB::table('assortment_product_space')->where('tenant_id', $tid)->exists()) {
            return $out;   // metres asked for can still be filled when only the widths file was sent
        }
        $keys = array_filter([$storeId ? 'store:' . $storeId : null, 'format:' . ShelfSpace::formatSlug($format)]);
        foreach (DB::table('assortment_shelf_space')->where('tenant_id', $tid)->whereIn('scope_key', $keys)->get(['scope_key', 'category', 'metres']) as $r) {
            $cat = trim((string) $r->category);
            if (str_starts_with((string) $r->scope_key, 'store:') || ! isset($out['metres'][$cat])) {
                $out['metres'][$cat] = (float) $r->metres;
            }
        }
        foreach (DB::table('assortment_product_space')->where('tenant_id', $tid)->whereIn('store_key', $storeId ? [0, $storeId] : [0])
            ->get(['store_key', 'sku', 'width_cm', 'facings']) as $r) {
            $own = $storeId !== null && (int) $r->store_key === $storeId;
            if ($r->width_cm && ($own || ! isset($out['width'][$r->sku]))) {
                $out['width'][(string) $r->sku] = (float) $r->width_cm / 100;
            }
            if ($r->facings && ($own || ! isset($out['facings'][$r->sku]))) {
                $out['facings'][(string) $r->sku] = (int) $r->facings;
            }
        }

        return $out;
    }

    private function category(string $cat, array $c, string $kind, array $own, ?float $metres, ?string $metresFrom, array $space, array $products, float $season, int $donorCount, array $rules = []): array
    {
        $rows = [];
        foreach ($c['products'] as $sku => $p) {
            $share = count($p['stores']) / max(1, $donorCount);
            $s = $p['sales'];
            sort($s);
            $rows[$sku] = [
                'sku' => (string) $sku, 'name' => (string) (($products[$sku]['name'] ?? '') ?: $sku), 'sub' => $products[$sku]['subcategory'] ?? null,
                'carrying' => count($p['stores']), 'share' => round($share, 3),
                'band' => $share >= self::CORE ? 'core' : ($share >= self::EXPECTED ? 'expected' : 'niche'),
                'low' => round((Stats::percentile($s, 0.25) ?? 0) * $season, 2), 'mid' => round((Stats::median($s) ?? 0) * $season, 2),
                'high' => round((Stats::percentile($s, 0.75) ?? 0) * $season, 2), 'carried_here' => isset($own[(string) $sku]),
            ];
        }
        // For a remodel, products it carries outside the donors' range are part of the start too.
        if ($kind === AssortmentNewRange::REMODEL) {
            foreach ($own as $sku => $_) {
                if (($products[$sku]['category'] ?? null) === $cat && ! isset($rows[$sku])) {
                    $rows[$sku] = ['sku' => (string) $sku, 'name' => (string) (($products[$sku]['name'] ?? '') ?: $sku), 'sub' => $products[$sku]['subcategory'] ?? null,
                        'carrying' => 0, 'share' => 0.0, 'band' => 'local', 'low' => 0.0, 'mid' => 0.0, 'high' => 0.0, 'carried_here' => true];
                }
            }
        }
        // A deal's products come first and always stay (a floor counted on the products of this range).
        if ($rules !== []) {
            foreach ($rules as $rule) {
                if ($rule['kind'] === \App\Models\AssortmentConstraint::KEEP && ! isset($rows[(string) $rule['sku']]) && ($products[$rule['sku']]['category'] ?? null) === $cat) {
                    $rows[(string) $rule['sku']] = ['sku' => (string) $rule['sku'], 'name' => (string) (($products[$rule['sku']]['name'] ?? '') ?: $rule['sku']),
                        'sub' => $products[$rule['sku']]['subcategory'] ?? null, 'carrying' => 0, 'share' => 0.0, 'band' => 'niche', 'low' => 0.0, 'mid' => 0.0, 'high' => 0.0,
                        'carried_here' => isset($own[(string) $rule['sku']])];
                }
            }
            $pool = array_map('strval', array_keys($rows));
            $sales = array_map(fn ($r) => (float) $r['mid'], $rows);
            foreach ($rows as $sku => $r) {
                if (($k = CommercialConstraints::keeps($rules, (string) $sku, $pool, $products, $sales)) !== null) {
                    $rows[$sku]['deal'] = $k['why'];
                }
            }
        }
        $rank = ['core' => 0, 'expected' => 1, 'local' => 2, 'niche' => 3];
        $first = fn (array $r) => isset($r['deal']) ? 0 : (($kind === AssortmentNewRange::REMODEL && $r['carried_here'] && $r['band'] !== 'niche') ? 1 : 2);
        uasort($rows, fn ($a, $b) => [$first($a), $rank[$a['band']], -$a['mid'], $a['sku']] <=> [$first($b), $rank[$b['band']], -$b['mid'], $b['sku']]);

        $counts = $c['counts'];
        sort($counts);
        $target = (int) round(Stats::median($counts) ?? 0);
        if ($kind === AssortmentNewRange::REMODEL && $metres === null) {
            // No new metres: the remodel keeps at least the shelf it has, and nothing is removed for space.
            $target = max($target, count(array_filter($rows, fn ($r) => $r['carried_here'])));
        }
        $width = function (string $sku) use ($space) {
            $w = $space['width'][$sku] ?? null;
            $f = $space['facings'][$sku] ?? null;

            return $w === null ? null : $w * max(1, (int) ($f ?? 1));
        };
        $useMetres = $metres !== null && array_filter(array_keys($rows), fn ($s) => $width((string) $s) !== null) !== [];
        $known = array_values(array_filter(array_map(fn ($s) => $width((string) $s), array_keys($rows)), fn ($v) => $v !== null));
        $avgW = $known !== [] ? array_sum($known) / count($known) : null;

        $chosen = [];
        $used = 0.0;
        foreach ($rows as $sku => $r) {
            $eligible = isset($r['deal']) || $r['band'] === 'core' || $r['band'] === 'expected' || ($kind === AssortmentNewRange::REMODEL && $r['carried_here'] && $r['band'] === 'local');
            if (! $eligible) {
                continue;
            }
            if ($useMetres) {
                $m = $width((string) $sku) ?? $avgW;
                if ($used + $m > $metres + 1e-9 && ! isset($r['deal'])) {
                    continue;   // a deal's product stays even when the shelf is short (said in the note)
                }
                $used += $m;
                $r['metres'] = round($m, 3);
                $r['width_assumed'] = $width((string) $sku) === null;
            } elseif (! isset($r['deal']) && count($chosen) >= max($target, $kind === AssortmentNewRange::REMODEL ? 0 : 1)) {
                continue;
            }
            $chosen[$sku] = $r;
        }
        $state = function (array $r) use ($kind) {
            return $kind === AssortmentNewRange::NEW ? 'start' : ($r['carried_here'] ? 'keep' : 'add');
        };
        $list = array_values(array_map(fn ($r) => $r + ['state' => $state($r)], $chosen));
        $gone = $kind === AssortmentNewRange::REMODEL
            ? array_values(array_map(fn ($r) => $r + ['state' => 'remove'], array_filter($rows, fn ($r, $s) => $r['carried_here'] && ! isset($chosen[$s]), ARRAY_FILTER_USE_BOTH)))
            : [];

        return [
            'category' => $cat, 'target' => $target, 'donor_counts' => $counts, 'metres' => $metres, 'metres_from' => $useMetres ? $metresFrom : null,
            'metres_used' => $useMetres ? round($used, 2) : null, 'count' => count($list),
            'core' => count(array_filter($list, fn ($r) => $r['band'] === 'core')),
            'sales' => [round(array_sum(array_column($list, 'low')), 2), round(array_sum(array_column($list, 'mid')), 2), round(array_sum(array_column($list, 'high')), 2)],
            'products' => $list, 'remove' => $gone,
            'deals' => count(array_filter($list, fn ($r) => isset($r['deal']))),
            'note' => $metres !== null && ! $useMetres ? 'Metres given, but no product in this category has a width: sized to what the donors carry.'
                : ($useMetres && $used > $metres + 1e-9 ? 'The deals alone need more than the shelf\'s metres.' : null),
        ];
    }

    /** A new store has traded long enough for its own Assortment run to judge it. */
    public function ownHistory(AssortmentNewRange $r): bool
    {
        if ($r->store_id === null || $r->kind !== AssortmentNewRange::NEW) {
            return false;
        }
        $first = DB::table('sales_daily')->where('tenant_id', $r->tenant_id)->where('store_id', $r->store_id)->min('date');

        return $first !== null && Carbon::parse($first)->diffInDays(now()) >= self::OWN_HISTORY_DAYS;
    }
}
