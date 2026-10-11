<?php

namespace App\Platform\Intelligence\Clustering\Strategies;

use App\Platform\Intelligence\Clustering\ClusteringStrategy;
use App\Platform\Intelligence\Clustering\KMeans;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v2.4 — stores grouped by WHAT THEY SELL (Platform\Intelligence): each store's
 * category mix (its share of sales in each of the chain's main categories) and
 * its price level (average selling price per unit against the chain), over the
 * last 90 days. Two stores that look alike on paper but sell a different range
 * land apart; two that look different but sell alike land together.
 *
 *   vector    category shares (top categories, the rest pooled) + price index, z-scored
 *   k         about √n, at most 8, and never so many that a group falls under
 *             `min_size` (`clustering.range.min_size`, 5): small groups are folded
 *             into the nearest one
 *   stable    with the previous assignment given, a store stays in the group that
 *             holds most of its old peers unless another is clearly (10%+) closer —
 *             a week's noise does not move it
 *   label     the categories it over-sells (≥ 115% of the chain's share) and the price
 *             level: "Fresh · Bakery-led, premium" — explainable by construction
 *
 * Pure: computes and returns, never persists. Not one of the switchable platform
 * strategies (config/clustering.php): Assortment proposes it for admin approval.
 */
class RangeMixClustering implements ClusteringStrategy
{
    public const TOP_CATEGORIES = 15;

    public const STICKY = 1.10;

    public function method(): string
    {
        return 'range';
    }

    public function cluster(int $tenantId): array
    {
        return $this->clusterWith($tenantId);
    }

    /**
     * @param  array<int,string>  $previous  store id => previous group key (for stability)
     * @return array<int, array{key:string, label:string, store_ids:list<int>, params:array}>
     */
    public function clusterWith(int $tenantId, array $previous = [], ?string $asOf = null): array
    {
        $pr = $this->profiles($tenantId, $asOf);
        if ($pr === null) {
            return [];
        }
        ['stores' => $byStore, 'top' => $top, 'chain_share' => $chainShare, 'prices' => $prices, 'chain_price' => $chainPrice] = $pr;
        $n = count($byStore);

        // Raw features per store: share of each top category, the rest pooled, and the price index.
        $raw = [];
        foreach ($byStore as $id => $s) {
            $vec = [];
            foreach ($top as $c) {
                $vec[] = ($s['cats'][$c] ?? 0.0) / $s['rev'];
            }
            $vec[] = 1 - array_sum($vec);
            $vec[] = $prices[$id] !== null ? $prices[$id] / $chainPrice : 1.0;
            $raw[$id] = $vec;
        }
        $vectors = $this->zscore($raw);
        $ids = array_keys($vectors);
        $list = array_values($vectors);

        $min = max(2, (int) config('clustering.range.min_size', 5));
        $k = max(1, min((int) round(sqrt($n)), 8, intdiv($n, $min) ?: 1));
        $res = KMeans::cluster($list, $k);
        $assign = $res['assignments'];
        $cent = $res['centroids'];

        // Fold groups under the minimum into the nearest other group, smallest first.
        while (true) {
            $sizes = array_count_values($assign);
            asort($sizes);
            $small = array_key_first(array_filter($sizes, fn ($c) => $c < $min));
            if ($small === null || count($sizes) <= 1) {
                break;
            }
            foreach ($assign as $i => $c) {
                if ($c === $small) {
                    $best = null;
                    $bestD = INF;
                    foreach ($cent as $j => $cj) {
                        if ($j !== $small && isset($sizes[$j]) && ($d = $this->dist($list[$i], $cj)) < $bestD) {
                            [$best, $bestD] = [$j, $d];
                        }
                    }
                    $assign[$i] = $best;
                }
            }
            $cent = $this->centroids($list, $assign, $cent);
        }

        // Stability: keep a store with the group that holds most of its old peers, unless another is clearly closer.
        if ($previous !== []) {
            $members = [];
            foreach ($assign as $i => $c) {
                $members[$c][] = $ids[$i];
            }
            foreach ($assign as $i => $c) {
                $old = $previous[$ids[$i]] ?? null;
                if ($old === null) {
                    continue;
                }
                $oldPeers = array_keys(array_filter($previous, fn ($k) => $k === $old));
                $bestC = $c;
                $bestOverlap = -1;
                foreach ($members as $cj => $m) {
                    $o = count(array_intersect($m, $oldPeers));
                    if ($o > $bestOverlap) {
                        [$bestC, $bestOverlap] = [$cj, $o];
                    }
                }
                if ($bestC !== $c && $this->dist($list[$i], $cent[$bestC]) <= self::STICKY * $this->dist($list[$i], $cent[$c])) {
                    $assign[$i] = $bestC;
                }
            }
        }

        $groups = [];
        foreach ($assign as $i => $c) {
            $groups[$c][] = $ids[$i];
        }
        ksort($groups);
        $out = [];
        $n = 0;
        foreach ($groups as $storeIds) {
            $n++;
            sort($storeIds);
            [$label, $params] = $this->describe($storeIds, $byStore, $top, $chainShare, $prices, $chainPrice);
            $out[] = ['key' => 'range-' . $n, 'label' => $label, 'store_ids' => $storeIds, 'params' => $params];
        }

        return $out;
    }

    /**
     * What each store sells over the 90 days to $asOf: its sales by category, its
     * units, and its average price per unit; with the chain's top categories,
     * their share of the chain's sales, and the chain's median price. Shared by
     * the grouping and by the screens that draw it (v2.5).
     *
     * @return array{as_of:string, stores: array<int,array{cats:array<string,float>,rev:float,units:float}>, top: list<string>, chain_share: array<string,float>, prices: array<int,?float>, chain_price: float}|null
     */
    public function profiles(int $tenantId, ?string $asOf = null): ?array
    {
        $asOf ??= (string) (DB::table('sales_daily')->where('tenant_id', $tenantId)->max('date') ?? now()->toDateString());
        $from = Carbon::parse($asOf)->subDays(89)->toDateString();
        $rows = DB::select(
            "SELECT sd.store_id, TRIM(p.category) AS cat,
                    SUM(CASE WHEN sd.revenue > 0 THEN sd.revenue ELSE sd.units_sold * COALESCE(p.selling_price, 0) END) AS rev,
                    SUM(sd.units_sold) AS units
               FROM sales_daily sd JOIN products p ON p.tenant_id = sd.tenant_id AND p.sku = sd.sku
              WHERE sd.tenant_id = ? AND sd.date BETWEEN ? AND ? AND p.category IS NOT NULL AND TRIM(p.category) <> ''
           GROUP BY 1, 2",
            [$tenantId, $from, $asOf],
        );
        $byStore = $chainCat = [];
        foreach ($rows as $r) {
            $byStore[(int) $r->store_id]['cats'][(string) $r->cat] = (float) $r->rev;
            $byStore[(int) $r->store_id]['rev'] = ($byStore[(int) $r->store_id]['rev'] ?? 0.0) + (float) $r->rev;
            $byStore[(int) $r->store_id]['units'] = ($byStore[(int) $r->store_id]['units'] ?? 0.0) + (float) $r->units;
            $chainCat[(string) $r->cat] = ($chainCat[(string) $r->cat] ?? 0.0) + (float) $r->rev;
        }
        $byStore = array_filter($byStore, fn ($s) => ($s['rev'] ?? 0) > 0);
        ksort($byStore);
        if ($byStore === []) {
            return null;
        }
        arsort($chainCat);
        $top = array_slice(array_keys($chainCat), 0, self::TOP_CATEGORIES);
        $chainTotal = array_sum($chainCat);
        $chainShare = array_map(fn ($v) => $v / $chainTotal, array_intersect_key($chainCat, array_flip($top)));
        $prices = array_map(fn ($s) => $s['units'] > 0 ? $s['rev'] / $s['units'] : null, $byStore);
        $chainPrice = $this->median(array_values(array_filter($prices, fn ($p) => $p !== null))) ?: 1.0;

        return ['as_of' => $asOf, 'stores' => $byStore, 'top' => $top, 'chain_share' => $chainShare, 'prices' => $prices, 'chain_price' => $chainPrice];
    }

    private function describe(array $storeIds, array $byStore, array $top, array $chainShare, array $prices, float $chainPrice): array
    {
        $rev = array_sum(array_map(fn ($id) => $byStore[$id]['rev'], $storeIds));
        $index = [];
        foreach ($top as $c) {
            $share = array_sum(array_map(fn ($id) => $byStore[$id]['cats'][$c] ?? 0.0, $storeIds)) / max(1e-9, $rev);
            $index[$c] = ($chainShare[$c] ?? 0) > 0 ? $share / $chainShare[$c] : 1.0;
        }
        arsort($index);
        $lead = array_slice(array_keys(array_filter($index, fn ($v) => $v >= 1.15)), 0, 2);
        $p = $this->median(array_values(array_filter(array_map(fn ($id) => $prices[$id], $storeIds), fn ($v) => $v !== null)));
        $pi = $p !== null ? $p / $chainPrice : 1.0;
        $price = $pi >= 1.1 ? 'premium' : ($pi <= 0.9 ? 'value' : null);
        $label = ($lead !== [] ? implode(' · ', $lead) . '-led' : 'Balanced range') . ($price ? ', ' . $price : '');

        return [$label, [
            'store_count' => count($storeIds),
            'leads' => array_map(fn ($c) => ['category' => $c, 'index' => round($index[$c], 2)], $lead),
            'price_index' => round($pi, 3),
            'category_index' => array_map(fn ($v) => round($v, 3), $index),
        ]];
    }

    /** @param array<int,array<int,float>> $raw */
    private function zscore(array $raw): array
    {
        $dims = count(reset($raw));
        $out = $raw;
        for ($d = 0; $d < $dims; $d++) {
            $col = array_column($raw, $d);
            $m = array_sum($col) / count($col);
            $sd = sqrt(array_sum(array_map(fn ($x) => ($x - $m) ** 2, $col)) / count($col));
            foreach ($raw as $id => $v) {
                $out[$id][$d] = $sd > 0 ? ($v[$d] - $m) / $sd : 0.0;
            }
        }

        return $out;
    }

    private function centroids(array $list, array $assign, array $old): array
    {
        $dim = count($list[0]);
        $sum = $cnt = [];
        foreach ($assign as $i => $c) {
            $cnt[$c] = ($cnt[$c] ?? 0) + 1;
            for ($d = 0; $d < $dim; $d++) {
                $sum[$c][$d] = ($sum[$c][$d] ?? 0.0) + $list[$i][$d];
            }
        }
        $out = [];
        foreach ($old as $c => $v) {
            $out[$c] = isset($cnt[$c]) ? array_map(fn ($s) => $s / $cnt[$c], $sum[$c]) : $v;
        }

        return $out;
    }

    private function dist(array $a, array $b): float
    {
        $s = 0.0;
        foreach ($a as $i => $v) {
            $s += ($v - ($b[$i] ?? 0.0)) ** 2;
        }

        return sqrt($s);
    }

    private function median(array $v): ?float
    {
        if ($v === []) {
            return null;
        }
        sort($v);
        $n = count($v);

        return $n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }
}
