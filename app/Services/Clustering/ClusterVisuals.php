<?php

namespace App\Services\Clustering;

use App\Models\Store;
use App\Models\StoreCluster;
use App\Models\StoreFeature;
use App\Platform\Intelligence\Clustering\ClusterService;
use App\Support\Geo\Gazetteer;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;

/**
 * v2.5 — the store clusters drawn (Data → Store Clustering, a cluster's page,
 * Clustering method):
 *
 *   map       every store where it is, coloured by its group, sized by its
 *             daily sales. A store with no coordinates is placed at its city
 *             (or region) from the gazetteer, drawn as approximate; one with
 *             neither is counted, not drawn. Schematic: to scale, no base map,
 *             nothing leaves the app.
 *   profile   each group against the average store on the behavioural
 *             features (the store feature layer the clusters are built from)
 *   mix       stores per group, and every store by basket value and daily sales
 *
 * Read-only; cached ten minutes per tenant and method.
 */
class ClusterVisuals
{
    public const CACHE_SECONDS = 600;

    /** The behavioural features, as the store profile computes them. */
    public const FEATURES = [
        'avg_daily_revenue' => ['label' => 'Daily sales', 'kind' => 'money'],
        'avg_basket_value'  => ['label' => 'Basket value', 'kind' => 'money'],
        'avg_selling_price' => ['label' => 'Selling price', 'kind' => 'money'],
        'sku_productivity'  => ['label' => 'Sales per product', 'kind' => 'money'],
        'active_skus'       => ['label' => 'Products sold', 'kind' => 'count'],
        'promo_share'       => ['label' => 'On promotion', 'kind' => 'share'],
        'growth_ratio'      => ['label' => 'Growth', 'kind' => 'ratio'],
    ];

    public function __construct(private ClusterService $clusters) {}

    /** The tenant's active clusters, drawn. @return array{map: array, profile: ?array, donut: list<array>, scatter: list<array>, series: array, stores: int}|null */
    public function overview(int $tenantId, string $currency, ?int $focusId = null): ?array
    {
        $method = $this->clusters->activeMethod($tenantId);

        return Cache::remember("clusterviz:{$tenantId}:{$method}:" . ($focusId ?? 0) . ':' . $this->stamp($tenantId), self::CACHE_SECONDS, function () use ($tenantId, $method, $currency, $focusId) {
            $groups = $this->clusters->clustersFor($tenantId, $method)->sortBy('label')->values()
                ->map(fn (StoreCluster $c) => ['id' => $c->id, 'label' => (string) $c->label, 'store_ids' => $c->stores->pluck('id')->map(fn ($v) => (int) $v)->all()])
                ->filter(fn ($g) => $g['store_ids'] !== [])->values()->all();
            if ($groups === []) {
                return null;
            }

            return $this->draw($tenantId, $groups, $currency, $focusId);
        });
    }

    /**
     * Any grouping drawn (e.g. the behavioural preview on the Clustering method page).
     *
     * @param  list<array{label: string, store_ids: list<int>}>  $groups
     */
    public function draw(int $tenantId, array $groups, string $currency, ?int $focusId = null): array
    {
        $stores = Store::where('tenant_id', $tenantId)->get(['id', 'name', 'city', 'region', 'latitude', 'longitude'])->keyBy('id');
        $features = StoreFeature::where('tenant_id', $tenantId)->get()->keyBy('store_id');
        $money = fn ($v) => Money::compact((float) $v, $currency);

        $series = $donut = $points = $scatter = $landmarks = [];
        $placed = ['exact' => 0, 'city' => 0, 'none' => 0];
        $atCity = [];
        $maxDaily = max(1.0, (float) $features->max('avg_daily_revenue'));
        foreach ($groups as $i => $g) {
            $s = $i + 1;
            $series[$s] = $g['label'];
            $donut[] = ['label' => $g['label'], 'value' => count($g['store_ids']), 's' => $s, 'text' => count($g['store_ids']) . ' stores'];
            $inFocus = $focusId === null || (int) ($g['id'] ?? 0) === $focusId;
            foreach ($g['store_ids'] as $id) {
                $st = $stores[$id] ?? null;
                if ($st === null) {
                    continue;
                }
                $f = $features[$id] ?? null;
                $daily = (float) ($f?->avg_daily_revenue ?? 0);
                // Where it is: its own coordinates, else its city's, else not drawn.
                $lat = $st->latitude !== null ? (float) $st->latitude : null;
                $lng = $st->longitude !== null ? (float) $st->longitude : null;
                $approx = false;
                if ($lat === null || $lng === null || ($lat === 0.0 && $lng === 0.0)) {
                    $city = Gazetteer::find($st->city) ? $st->city : (Gazetteer::find($st->region) ? $st->region : null);
                    $at = $city ? Gazetteer::find($city) : null;
                    if ($at === null) {
                        $placed['none']++;
                        $lat = $lng = null;
                    } else {
                        // Spread the stores of one city on a small spiral so each can be seen and hovered.
                        $n = $atCity[$city] = ($atCity[$city] ?? -1) + 1;
                        [$lat, $lng] = [$at[0] + 0.03 * sqrt($n) * sin($n * 2.4), $at[1] + 0.03 * sqrt($n) * cos($n * 2.4)];
                        $approx = true;
                        $placed['city']++;
                        $landmarks[Gazetteer::label($city)] = ['lat' => $at[0], 'lng' => $at[1], 'label' => Gazetteer::label($city)];
                    }
                } else {
                    $placed['exact']++;
                }
                $where = collect([$st->city, $st->region])->filter()->implode(', ');
                if ($lat !== null) {
                    $points[] = ['lat' => $lat, 'lng' => $lng, 's' => $s, 'approx' => $approx, 'dim' => ! $inFocus,
                        'r' => round(4 + 7 * sqrt(max(0.0, $daily) / $maxDaily), 1),
                        'tip' => $st->name . ' · ' . $g['label'] . ($where !== '' ? ' · ' . $where : '') . ($daily > 0 ? ' · ' . $money($daily) . ' a day' : '')
                            . ($approx ? ' (placed at its city: no coordinates on file)' : '')];
                }
                if ($f !== null && $f->avg_basket_value !== null && $f->avg_daily_revenue !== null) {
                    $scatter[] = ['x' => (float) $f->avg_basket_value, 'y' => (float) $f->avg_daily_revenue, 's' => $s, 'hollow' => ! $inFocus,
                        'tip' => $st->name . ' · ' . $g['label'] . ': basket ' . $money($f->avg_basket_value) . ', ' . $money($f->avg_daily_revenue) . ' a day'];
                }
            }
        }

        return [
            'series'  => $series,
            'donut'   => $donut,
            'map'     => ['points' => $points, 'landmarks' => array_values($landmarks), 'placed' => $placed],
            'profile' => $this->profile($groups, $features, $money),
            'scatter' => $scatter,
            'stores'  => (int) array_sum(array_column($donut, 'value')),
        ];
    }

    /** Each group's average on each feature against the average store (100 = the same). */
    private function profile(array $groups, $features, callable $money): ?array
    {
        if ($features->isEmpty()) {
            return null;
        }
        $fmt = fn (string $kind, float $v) => match ($kind) {
            'money' => $money($v),
            'share' => number_format($v * 100, 1) . '%',
            'ratio' => '× ' . number_format($v, 2),
            default => number_format($v),
        };
        $overall = [];
        foreach (array_keys(self::FEATURES) as $k) {
            $vals = $features->pluck($k)->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);
            $overall[$k] = $vals->isEmpty() ? null : $vals->avg();
        }
        $rows = $cells = [];
        foreach ($groups as $g) {
            $key = (string) ($g['id'] ?? $g['label']);
            $members = $features->toBase()->only($g['store_ids']);   // by store id (an Eloquent collection's only() goes by the model's own id)
            $rows[] = ['key' => $key, 'label' => $g['label'], 'sub' => count($g['store_ids']) . ' stores'];
            foreach (self::FEATURES as $k => $def) {
                $vals = $members->pluck($k)->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);
                $o = $overall[$k];
                if ($vals->isEmpty() || $o === null || abs($o) < 1e-9) {
                    continue;
                }
                $avg = $vals->avg();
                $idx = $avg / $o;
                $cells[$key][$k] = ['v' => $idx, 'text' => (string) (int) round($idx * 100),
                    'tip' => $g['label'] . ' · ' . $def['label'] . ': ' . $fmt($def['kind'], $avg) . ' on average, against ' . $fmt($def['kind'], $o)
                        . ' for the average store (' . (int) round($idx * 100) . ')'];
            }
        }

        return ['rows' => $rows, 'cols' => array_map(fn ($k, $d) => ['key' => $k, 'label' => $d['label']], array_keys(self::FEATURES), self::FEATURES), 'cells' => $cells];
    }

    /** Changes when the clusters, the stores or their profiles change. */
    private function stamp(int $tenantId): string
    {
        return md5(json_encode([
            StoreCluster::where('tenant_id', $tenantId)->max('updated_at'),
            Store::where('tenant_id', $tenantId)->max('updated_at'),
            StoreFeature::where('tenant_id', $tenantId)->max('computed_at'),
            \Illuminate\Support\Facades\DB::table('store_cluster_members')->join('store_clusters', 'store_clusters.id', '=', 'store_cluster_members.store_cluster_id')
                ->where('store_clusters.tenant_id', $tenantId)->count(),
        ]));
    }
}
