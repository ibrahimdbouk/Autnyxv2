<?php

namespace App\Platform\Intelligence;

use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Store feature layer (Platform\Intelligence) — the platform's durable behavioural
 * asset. Computes a per-store feature vector from sales + products + sku_profiles,
 * over a trailing window, and writes it to `store_features`.
 *
 * Two passes: (1) raw aggregates per store; (2) tenant-relative tiers — a store is
 * "premium" or "large" relative to *this tenant's* other stores — plus a
 * plain-language descriptor. The tiers are the explainability raw material that
 * behavioural clustering (Phase 3) will build its cluster descriptions on.
 *
 * Clusters are a projection of this; keep continuous features here, not in the
 * cluster tables.
 */
class StoreProfiler
{
    /** Rebuild features for every store of a tenant. Returns the number profiled. */
    public function rebuild(int $tenantId, int $windowDays = 90): int
    {
        $since = now()->subDays($windowDays)->toDateString();
        $d30 = now()->subDays(30)->toDateString();
        $d60 = now()->subDays(60)->toDateString();

        $core = DB::table('sales_transactions')
            ->where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->groupBy('store_id')
            ->selectRaw(
                'store_id,'
                . ' SUM(total_amount) AS revenue,'
                . ' SUM(quantity) AS units,'
                . ' COUNT(DISTINCT sku) AS active_skus,'
                . ' COUNT(DISTINCT transaction_id) AS basket_count,'
                . ' SUM(CASE WHEN COALESCE(discount, 0) > 0 THEN total_amount ELSE 0 END) AS promo_revenue'
            )
            ->get()->keyBy('store_id');

        $growth = DB::table('sales_transactions')
            ->where('tenant_id', $tenantId)
            ->where('date', '>=', $d60)
            ->groupBy('store_id')
            ->selectRaw(
                'store_id,'
                . ' SUM(CASE WHEN date >= ? THEN total_amount ELSE 0 END) AS rev_recent,'
                . ' SUM(CASE WHEN date < ? THEN total_amount ELSE 0 END) AS rev_prior',
                [$d30, $d30]
            )
            ->get()->keyBy('store_id');

        $catRows = DB::table('sales_transactions as st')
            ->join('products as p', fn ($j) => $j->on('p.tenant_id', '=', 'st.tenant_id')->on('p.sku', '=', 'st.sku'))
            ->where('st.tenant_id', $tenantId)
            ->where('st.date', '>=', $since)
            ->whereNotNull('p.category')
            ->groupBy('st.store_id', 'p.category')
            ->selectRaw('st.store_id AS store_id, p.category AS category, SUM(st.total_amount) AS rev')
            ->get()->groupBy('store_id');

        $segRows = DB::table('sku_profiles')
            ->where('tenant_id', $tenantId)
            ->groupBy('store_id', 'segment')
            ->selectRaw('store_id, segment, COUNT(*) AS n')
            ->get()->groupBy('store_id');

        // ---- Pass 1: raw features per store ----
        $rows = [];
        foreach (Store::where('tenant_id', $tenantId)->pluck('id') as $storeId) {
            $c = $core->get($storeId);
            $revenue = (float) ($c->revenue ?? 0);
            $units = (float) ($c->units ?? 0);
            $activeSkus = (int) ($c->active_skus ?? 0);
            $baskets = (int) ($c->basket_count ?? 0);
            $promoRev = (float) ($c->promo_revenue ?? 0);

            $g = $growth->get($storeId);
            $revRecent = (float) ($g->rev_recent ?? 0);
            $revPrior = (float) ($g->rev_prior ?? 0);

            [$topCategory, $topShare, $categoryMix] = $this->topCategory($catRows->get($storeId), $revenue);
            [$dominantSegment, $segmentMix] = $this->dominantSegment($segRows->get($storeId));

            $rows[$storeId] = [
                'revenue'            => $revenue,
                'units'              => $units,
                'active_skus'        => $activeSkus,
                'basket_count'       => $baskets,
                'avg_daily_revenue'  => $windowDays > 0 ? $revenue / $windowDays : 0,
                'growth_ratio'       => $revPrior > 0 ? round($revRecent / $revPrior, 4) : null,
                'avg_basket_value'   => $baskets > 0 ? round($revenue / $baskets, 4) : null,
                'avg_basket_units'   => $baskets > 0 ? round($units / $baskets, 4) : null,
                'avg_selling_price'  => $units > 0 ? round($revenue / $units, 4) : null,
                'sku_productivity'   => $activeSkus > 0 ? round($revenue / $activeSkus, 4) : null,
                'promo_share'        => $revenue > 0 ? round($promoRev / $revenue, 4) : null,
                'top_category'       => $topCategory,
                'top_category_share' => $topShare,
                'dominant_segment'   => $dominantSegment,
                'features'           => ['category_mix' => $categoryMix, 'segment_mix' => $segmentMix],
            ];
        }

        // ---- Pass 2: tenant-relative tiers + descriptor ----
        $sizeDist = $this->distribution(array_map(fn ($r) => $r['revenue'] > 0 ? $r['revenue'] : null, $rows));
        $priceDist = $this->distribution(array_map(fn ($r) => $r['avg_selling_price'], $rows));
        $basketDist = $this->distribution(array_map(fn ($r) => $r['avg_basket_value'], $rows));

        $count = 0;
        foreach ($rows as $storeId => $r) {
            $sizeTier = $r['revenue'] > 0 ? $this->map($this->tier($r['revenue'], $sizeDist), ['low' => 'small', 'mid' => 'medium', 'high' => 'large']) : null;
            $priceTier = $this->map($this->tier($r['avg_selling_price'], $priceDist), ['low' => 'value', 'mid' => 'mid', 'high' => 'premium']);
            $basketTier = $this->tier($r['avg_basket_value'], $basketDist);

            StoreFeature::updateOrCreate(
                ['tenant_id' => $tenantId, 'store_id' => $storeId],
                array_merge($r, [
                    'window_days'  => $windowDays,
                    'size_tier'    => $sizeTier,
                    'price_tier'   => $priceTier,
                    'basket_tier'  => $basketTier,
                    'descriptor'   => $this->descriptor($r, $sizeTier, $priceTier, $basketTier),
                    'computed_at'  => now(),
                ])
            );
            $count++;
        }

        return $count;
    }

    /** Rebuild features for every tenant. Returns [tenantId => storesProfiled]. */
    public function rebuildAll(int $windowDays = 90): array
    {
        $out = [];
        Tenant::query()->orderBy('id')->pluck('id')->each(function (int $id) use (&$out, $windowDays) {
            $out[$id] = $this->rebuild($id, $windowDays);
        });

        return $out;
    }

    /** @return array{0:?string,1:?float,2:array<string,float>} top category, its revenue share, and the mix */
    private function topCategory($rows, float $revenue): array
    {
        if (! $rows || $rows->isEmpty() || $revenue <= 0) {
            return [null, null, []];
        }

        $mix = [];
        foreach ($rows as $row) {
            $mix[$row->category] = round(((float) $row->rev) / $revenue, 4);
        }
        arsort($mix);
        $mix = array_slice($mix, 0, 8, true);
        $top = array_key_first($mix);

        return [$top, $mix[$top], $mix];
    }

    /** @return array{0:?string,1:array<string,int>} dominant segment and the segment mix */
    private function dominantSegment($rows): array
    {
        if (! $rows || $rows->isEmpty()) {
            return [null, []];
        }

        $mix = [];
        foreach ($rows as $row) {
            $mix[$row->segment] = (int) $row->n;
        }
        arsort($mix);

        return [array_key_first($mix), $mix];
    }

    /** The sorted non-null population, or null if too few stores to tier meaningfully. */
    private function distribution(array $values): ?array
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        sort($values);

        return count($values) < 3 ? null : $values;
    }

    /** Rank-fraction tier: low/mid/high thirds of the tenant's store distribution. */
    private function tier(?float $value, ?array $dist): ?string
    {
        if ($value === null || $dist === null) {
            return null;
        }

        $rank = 0;
        foreach ($dist as $v) {
            if ($v < $value) {
                $rank++;
            }
        }
        $frac = $rank / count($dist);

        return $frac < 1 / 3 ? 'low' : ($frac < 2 / 3 ? 'mid' : 'high');
    }

    private function map(?string $tier, array $labels): ?string
    {
        return $tier === null ? null : ($labels[$tier] ?? $tier);
    }

    private function descriptor(array $r, ?string $size, ?string $price, ?string $basket): string
    {
        if ($r['revenue'] <= 0) {
            return 'No recent sales';
        }

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

        if ($parts === []) {
            return $r['dominant_segment'] ? ucfirst($r['dominant_segment']) . ' demand' : 'Active store';
        }

        return implode(' · ', $parts);
    }
}
