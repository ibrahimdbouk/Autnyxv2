<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGrouping;
use App\Models\Store;
use App\Platform\Intelligence\Clustering\Strategies\RangeMixClustering;
use Illuminate\Support\Facades\Cache;

/**
 * v2.5 — the store groups drawn (Store Groups screen): how many stores each
 * group holds, what each group sells more or less of than the chain (the
 * figures its label comes from), every store placed by its price level and how
 * far its mix sits from the chain's, and — where the store file has
 * coordinates — where the stores are.
 *
 *   mix difference   half the gap between the store's category shares and the
 *                    chain's: the share of its sales that sits in different
 *                    categories (0% = sells exactly the chain's mix)
 *   price level      its average price per unit against the chain's median store
 *   map              ClusterVisuals' schematic map: coordinates, else the store's city
 *
 * Read-only; worked out from the last 90 days of sales, cached an hour.
 */
class GroupVisuals
{
    public const CACHE_MINUTES = 60;

    public function __construct(private RangeMixClustering $mix) {}

    public function build(int $tenantId, AssortmentGrouping $g): ?array
    {
        $asOf = (string) (\Illuminate\Support\Facades\DB::table('sales_daily')->where('tenant_id', $tenantId)->max('date') ?? '');

        return Cache::remember("assortment:groupviz:{$tenantId}:{$g->id}:{$g->version}:{$asOf}", now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->compute($tenantId, $g));
    }

    private function compute(int $tenantId, AssortmentGrouping $g): ?array
    {
        $groups = array_values((array) $g->groups);
        if ($groups === []) {
            return null;
        }
        $pr = $this->mix->profiles($tenantId) ?? ['as_of' => null, 'stores' => [], 'top' => [], 'chain_share' => [], 'prices' => [], 'chain_price' => 1.0];
        $stores = Store::where('tenant_id', $tenantId)->get(['id', 'name', 'format', 'latitude', 'longitude'])->keyBy('id');

        $series = $donut = $rows = $points = [];
        foreach ($groups as $i => $grp) {
            $s = $i + 1;
            $series[$s] = (string) $grp['label'];
            $donut[] = ['label' => (string) $grp['label'], 'value' => count((array) $grp['members']), 's' => $s,
                'text' => count((array) $grp['members']) . ' stores'];
            $rows[] = ['key' => (string) $grp['key'], 'label' => (string) $grp['label'], 'sub' => count((array) $grp['members']) . ' stores'];
        }

        // What each group sells against the chain: the index its label is built from.
        $cats = $pr['top'];
        if ($cats === []) {
            foreach ($groups as $grp) {
                $cats = array_values(array_unique([...$cats, ...array_keys((array) ($grp['profile']['category_index'] ?? []))]));
            }
        }
        $cats = array_slice($cats, 0, 12);
        $cells = [];
        foreach ($groups as $grp) {
            $members = array_map('intval', (array) $grp['members']);
            $index = (array) ($grp['profile']['category_index'] ?? []);
            $rev = 0.0;
            $catRevs = [];
            foreach ($members as $id) {
                $st = $pr['stores'][$id] ?? null;
                if ($st === null) {
                    continue;
                }
                $rev += $st['rev'];
                foreach ($cats as $c) {
                    $catRevs[$c] = ($catRevs[$c] ?? 0.0) + ($st['cats'][$c] ?? 0.0);
                }
            }
            foreach ($cats as $c) {
                $share = $rev > 0 ? ($catRevs[$c] ?? 0.0) / $rev : null;
                $chain = $pr['chain_share'][$c] ?? null;
                $v = isset($index[$c]) ? (float) $index[$c] : ($share !== null && $chain ? $share / $chain : null);
                $cells[(string) $grp['key']][$c] = $v === null ? null : [
                    'v' => $v, 'text' => (string) (int) round($v * 100),
                    'tip' => $grp['label'] . ' · ' . $c . ': ' . (int) round($v * 100) . '% of the chain\'s share'
                        . ($share !== null ? ' (' . number_format($share * 100, 1) . '% of the group\'s sales against ' . number_format((float) $chain * 100, 1) . '% for the chain)' : ''),
                ];
            }
        }

        // Every store by price level and how far its mix sits from the chain's.
        foreach ($groups as $i => $grp) {
            foreach (array_map('intval', (array) $grp['members']) as $id) {
                $st = $pr['stores'][$id] ?? null;
                $name = (string) ($stores[$id]->name ?? ('Store ' . $id));
                if ($st !== null && $st['rev'] > 0) {
                    $gap = 0.0;
                    $inTop = 0.0;
                    foreach ($pr['top'] as $c) {
                        $sh = ($st['cats'][$c] ?? 0.0) / $st['rev'];
                        $inTop += $sh;
                        $gap += abs($sh - ($pr['chain_share'][$c] ?? 0.0));
                    }
                    $gap += abs((1 - $inTop) - (1 - array_sum($pr['chain_share'])));
                    $price = ($pr['prices'][$id] ?? null) !== null ? $pr['prices'][$id] / $pr['chain_price'] : null;
                    if ($price !== null) {
                        $points[] = ['x' => round($price, 4), 'y' => round($gap / 2, 4), 's' => $i + 1,
                            'tip' => $name . ' · ' . $grp['label'] . ': prices ' . (int) round($price * 100) . '% of the chain, '
                                . (int) round($gap / 2 * 100) . '% of its sales in a different mix than the chain'];
                    }
                }
            }
        }

        return [
            'as_of'   => $pr['as_of'] ?? null,
            'series'  => $series,
            'donut'   => $donut,
            'heat'    => ['rows' => $rows, 'cols' => array_map(fn ($c) => ['key' => $c, 'label' => $c], $cats), 'cells' => $cells],
            'scatter' => $points,
            // A map only when most stores have coordinates (and at least three do).
            // v2.5 — the same schematic map as Store Clustering: coordinates, else the store's city (drawn as approximate).
            'map'     => app(\App\Services\Clustering\ClusterVisuals::class)->draw($tenantId,
                array_map(fn ($g) => ['label' => (string) $g['label'], 'store_ids' => array_map('intval', (array) $g['members'])], $groups),
                \App\Support\Money::normalize(\App\Models\Tenant::find($tenantId)?->currency))['map'],
            'stores'  => (int) array_sum(array_column($donut, 'value')),
        ];
    }
}
