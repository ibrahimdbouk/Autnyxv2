<?php

namespace App\Platform\Intelligence\Clustering\Strategies;

use App\Models\StoreFeature;
use App\Platform\Intelligence\Clustering\ClusteringStrategy;
use App\Platform\Intelligence\Clustering\KMeans;
use Illuminate\Support\Collection;

/**
 * v1 behavioural clustering (Platform\Intelligence). Groups stores by how they
 * actually trade — not their master data — using the store feature layer.
 *
 * Two Dubai hypermarkets that are structurally identical but behave differently
 * (premium/high-basket vs value-driven) land in different clusters here, which is
 * exactly what attribute clustering cannot do and what assortment peers need.
 *
 * Explainable by construction: the vector uses continuous features, but each
 * cluster is LABELLED from the modal tier profile of its members (computed in
 * StoreProfiler, tenant-relative), and its params carry the defining averages —
 * so "why is this a cluster / why is this store here" is answerable in plain terms.
 *
 * Ships dark: selected only when CLUSTERING_STRATEGY=demand. Reads store_features,
 * so StoreProfiler must have run (nightly it does).
 */
class DemandClustering implements ClusteringStrategy
{
    /** Continuous dimensions the vector is built from (z-scored before clustering). */
    private const DIMS = [
        'avg_daily_revenue',
        'avg_basket_value',
        'avg_selling_price',
        'sku_productivity',
        'promo_share',
        'active_skus',
        'growth_ratio',
    ];

    public function method(): string
    {
        return 'demand';
    }

    public function cluster(int $tenantId): array
    {
        /** @var Collection<int,StoreFeature> $features */
        $features = StoreFeature::query()
            ->where('tenant_id', $tenantId)
            ->where('revenue', '>', 0)   // only stores that actually trade
            ->orderBy('store_id')
            ->get();

        $n = $features->count();
        if ($n === 0) {
            return [];
        }

        // Column means / std for z-scoring (imputing nulls to the column mean → 0 after z).
        $mean = [];
        $std = [];
        foreach (self::DIMS as $dim) {
            $vals = $features->pluck($dim)->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->all();
            $m = $vals ? array_sum($vals) / count($vals) : 0.0;
            $var = $vals ? array_sum(array_map(fn ($x) => ($x - $m) ** 2, $vals)) / count($vals) : 0.0;
            $mean[$dim] = $m;
            $std[$dim] = sqrt($var);
        }

        $vectors = [];
        $ids = [];
        foreach ($features as $f) {
            $vec = [];
            foreach (self::DIMS as $dim) {
                $x = $f->$dim !== null ? (float) $f->$dim : $mean[$dim];
                $vec[] = $std[$dim] > 0 ? ($x - $mean[$dim]) / $std[$dim] : 0.0;
            }
            $vectors[] = $vec;
            $ids[] = (int) $f->store_id;
        }

        $result = KMeans::cluster($vectors, $this->chooseK($n));

        // Group store ids by assignment.
        $groups = [];
        foreach ($result['assignments'] as $i => $c) {
            $groups[$c][] = $ids[$i];
        }

        $byStore = $features->keyBy('store_id');
        $out = [];
        $idx = 0;
        foreach ($groups as $storeIds) {
            if ($storeIds === []) {
                continue;
            }
            $members = collect($storeIds)->map(fn ($id) => $byStore[$id]);
            $idx++;
            $out[] = [
                'key'       => 'demand-' . $idx,
                'label'     => $this->label($members),
                'store_ids' => $storeIds,
                'params'    => $this->params($members),
            ];
        }

        return $this->distinct($out, $byStore);
    }

    /** How a group stands apart on each dimension, in words: [above, below]. */
    private const LEANS = [
        'avg_daily_revenue' => ['higher sales', 'lower sales'],
        'avg_basket_value'  => ['bigger baskets', 'smaller baskets'],
        'avg_selling_price' => ['higher prices', 'lower prices'],
        'sku_productivity'  => ['more sales per product', 'less sales per product'],
        'promo_share'       => ['more on promotion', 'less on promotion'],
        'active_skus'       => ['wider range', 'narrower range'],
        'growth_ratio'      => ['growing', 'slowing'],
    ];

    /**
     * v2.5 — no two groups share a label. The tier profile can be the same for
     * two groups (or missing: "Behavioural group"). Among the groups that share
     * a label, the dimension where they differ most (relative to the average
     * store) tells them apart: the group lowest on it reads "· lower prices",
     * the highest "· higher prices", any between them "· in between".
     */
    private function distinct(array $out, Collection $byStore): array
    {
        $byLabel = [];
        foreach ($out as $i => $g) {
            $byLabel[$g['label']][] = $i;
        }
        $mean = [];
        foreach (array_keys(self::LEANS) as $d) {
            $vals = $byStore->pluck($d)->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);
            $mean[$d] = $vals->isEmpty() ? null : $vals->avg();
        }
        foreach ($byLabel as $label => $idx) {
            if (count($idx) < 2) {
                continue;
            }
            // Each group's average on each dimension.
            $avg = [];
            foreach ($idx as $i) {
                foreach (array_keys(self::LEANS) as $d) {
                    $vals = collect($out[$i]['store_ids'])->map(fn ($id) => $byStore[$id]->{$d} ?? null)->filter(fn ($v) => $v !== null);
                    $avg[$i][$d] = $vals->isEmpty() ? null : (float) $vals->avg();
                }
            }
            $best = null;
            $bestSpread = 0.0;
            foreach (array_keys(self::LEANS) as $d) {
                $vals = array_filter(array_map(fn ($i) => $avg[$i][$d], $idx), fn ($v) => $v !== null);
                if (count($vals) !== count($idx) || ! $mean[$d]) {
                    continue;
                }
                $spread = (max($vals) - min($vals)) / abs($mean[$d]);
                if ($spread > $bestSpread) {
                    [$best, $bestSpread] = [$d, $spread];
                }
            }
            if ($best === null || $bestSpread < 0.05) {
                $n = 0;
                foreach ($idx as $i) {
                    $out[$i]['label'] = $label . ' ' . ++$n;
                }

                continue;
            }
            usort($idx, fn ($x, $y) => $avg[$x][$best] <=> $avg[$y][$best]);
            $last = count($idx) - 1;
            foreach ($idx as $rank => $i) {
                $out[$i]['label'] = $label . ' · ' . match (true) {
                    $rank === 0     => self::LEANS[$best][1],
                    $rank === $last => self::LEANS[$best][0],
                    default         => 'in between' . ($last > 2 ? ' ' . $rank : ''),
                };
            }
        }

        return $out;
    }

    /** ~sqrt(n) clusters, bounded — enough resolution without fragmenting. */
    private function chooseK(int $n): int
    {
        $maxK = (int) config('clustering.demand.max_k', 8);

        return max(1, min((int) round(sqrt($n)), $maxK, $n));
    }

    /** @param Collection<int,StoreFeature> $members */
    private function label(Collection $members): string
    {
        $size = $this->mode($members->pluck('size_tier'));
        $price = $this->mode($members->pluck('price_tier'));
        $basket = $this->mode($members->pluck('basket_tier'));

        $parts = [];
        if ($size) {
            $parts[] = ucfirst($size) . '-format';
        }
        if ($price) {
            $parts[] = $price;
        }
        if ($basket) {
            $parts[] = $basket . '-basket';
        }

        return $parts === [] ? 'Behavioural group' : implode(' · ', $parts);
    }

    /** @param Collection<int,StoreFeature> $members */
    private function params(Collection $members): array
    {
        return [
            'store_count'       => $members->count(),
            'size_tier'         => $this->mode($members->pluck('size_tier')),
            'price_tier'        => $this->mode($members->pluck('price_tier')),
            'basket_tier'       => $this->mode($members->pluck('basket_tier')),
            'dominant_segment'  => $this->mode($members->pluck('dominant_segment')),
            'avg_daily_revenue' => round((float) $members->avg('avg_daily_revenue'), 2),
            'avg_basket_value'  => round((float) $members->avg('avg_basket_value'), 2),
            'avg_selling_price' => round((float) $members->avg('avg_selling_price'), 2),
        ];
    }

    /** Most common non-null value, or null. */
    private function mode(Collection $values): ?string
    {
        $counts = $values->filter(fn ($v) => $v !== null && $v !== '')->countBy();
        if ($counts->isEmpty()) {
            return null;
        }

        return $counts->sortDesc()->keys()->first();
    }
}
