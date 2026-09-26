<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGap;
use App\Platform\Explainability\ExplanationBuilder;
use App\Platform\Recommendation\Recommendation;
use App\Support\Money;

/**
 * Assortment A2 + A3 — the peer benchmark and the three range decisions for
 * one peer group. Deterministic; every figure it emits carries the evidence it
 * was computed from.
 *
 *   ADD             not carried here; carried and sold by most peers; expected
 *                   sales here from the peers' share index × this store's
 *                   category sales, less what similar products lose (a range).
 *   DELIST          carried ≥ 90 days, usually in stock, selling far below peers
 *                   and at the bottom of its category here — and not must-stock,
 *                   seasonal, or the only product of its kind in the store.
 *   STOCKOUT-HIDDEN carried, peers sell it well, but out of stock here most of
 *                   the time: the range is right, the shelf is wrong. Never a delist.
 *
 * Peers are the OTHER stores of the group that qualify (carried ≥ 28 days,
 * in stock ≥ 80%) — the store being judged is never its own benchmark.
 */
class GapEngine
{
    /** @var array<string,true> "storeId|sku" and "*|sku" */
    private array $mustStock = [];

    /** @var array<string,float> "storeId|sku" => stock value (on hand × cost) */
    private array $stockValue = [];

    /** @var array<int,string> */
    private array $storeNames = [];

    private float $qualityFactor = 1.0;

    /** @var array<int,string> */
    private array $qualityReasons = [];

    private bool $allowDelists = true;

    private string $currency = 'USD';

    /** @param array<string,array<string,mixed>> $products sku => facts */
    public function __construct(private readonly array $products) {}

    /** @param array<string,true> $mustStock */
    public function withContext(
        array $mustStock,
        array $stockValue,
        array $storeNames,
        float $qualityFactor,
        array $qualityReasons,
        bool $allowDelists,
        string $currency,
    ): self {
        $this->mustStock      = $mustStock;
        $this->stockValue     = $stockValue;
        $this->storeNames     = $storeNames;
        $this->qualityFactor  = $qualityFactor;
        $this->qualityReasons = $qualityReasons;
        $this->allowDelists   = $allowDelists;
        $this->currency       = $currency;

        return $this;
    }

    /**
     * Benchmark rows for every SKU carried in the group.
     *
     * @return array<string,array<string,mixed>> sku => benchmark
     */
    public function benchmark(GroupData $g): array
    {
        $size = count($g->members);
        $out  = [];
        foreach ($g->carriedSkus() as $sku) {
            $carrying = 0;
            $shares = $units = $revs = $avails = [];
            foreach ($g->members as $storeId) {
                if (! $g->carries($storeId, $sku)) {
                    continue;
                }
                $carrying++;
                if ($g->qualifies($storeId, $sku)) {
                    $p = $g->perf[$storeId][$sku];
                    $shares[] = $p['share'];
                    $units[]  = $p['units_per_day'];
                    $revs[]   = $p['rev_per_day'];
                    if ($p['availability'] !== null) {
                        $avails[] = $p['availability'];
                    }
                }
            }
            $out[$sku] = [
                'sku'                    => $sku,
                'product_id'             => $this->products[$sku]['id'] ?? null,
                'group_size'             => $size,
                'carrying'               => $carrying,
                'qualifying'             => count($shares),
                'carried_share'          => $size > 0 ? round($carrying / $size, 4) : 0,
                'share_index_p25'        => Stats::percentile($shares, 0.25),
                'share_index_median'     => Stats::median($shares),
                'share_index_p75'        => Stats::percentile($shares, 0.75),
                'units_per_day_median'   => Stats::median($units),
                'revenue_per_day_median' => Stats::median($revs),
                'availability_median'    => Stats::median($avails),
            ];
        }

        return $out;
    }

    /**
     * The decisions for the stores judged against this group.
     *
     * @param  array<int,int>  $targets
     * @param  array<string,array<string,mixed>>  $benchmark
     * @param  array<string,mixed>  $group  key, label, basis
     * @return array{gaps: array<int,array<string,mixed>>, counts: array<string,int>}
     */
    public function evaluate(GroupData $g, array $targets, array $benchmark, array $group): array
    {
        $cfg    = config('assortment');
        $gaps   = [];
        $counts = ['add' => 0, 'delist' => 0, 'stockout_hidden' => 0, 'skipped_no_category_sales' => 0, 'skipped_speculative_delist' => 0];

        // Category bar for adds: the P40 of the group's median share index per product.
        $byCategory = [];
        foreach ($benchmark as $sku => $b) {
            $cat = $this->products[$sku]['category'] ?? null;
            if ($cat !== null && $b['qualifying'] >= $cfg['min_qualifying_peers'] && $b['share_index_median'] !== null) {
                $byCategory[$cat][] = $b['share_index_median'];
            }
        }
        $categoryBar = array_map(fn (array $v) => Stats::percentile($v, (float) $cfg['add_rate_percentile']), $byCategory);

        foreach ($targets as $storeId) {
            $ownRank = $this->ownCategoryRanks($g, $storeId);

            foreach ($benchmark as $sku => $b) {
                $product = $this->products[$sku] ?? null;
                if ($product === null || $product['category'] === null) {
                    continue;
                }
                $peers = $this->peers($g, $storeId, $sku);
                if (count($peers) < $cfg['min_qualifying_peers']) {
                    continue;
                }
                $shares  = array_column($peers, 'share');
                $median  = Stats::median($shares);
                $tier    = $this->tier($shares);
                $catRate = $g->categoryRevenuePerDay[$storeId][$product['category']] ?? 0.0;

                if (! $g->carries($storeId, $sku)) {
                    $gap = $this->add($g, $storeId, $sku, $product, $b, $peers, $median, $tier, $catRate, $categoryBar[$product['category']] ?? null, $group, $counts);
                } else {
                    $gap = $this->stockoutHidden($g, $storeId, $sku, $product, $peers, $shares, $median, $tier, $catRate, $group)
                        ?? $this->delist($g, $storeId, $sku, $product, $peers, $median, $tier, $ownRank, $group, $counts);
                }
                if ($gap !== null) {
                    $counts[$gap['type']]++;
                    $gaps[] = $gap;
                }
            }
        }

        return ['gaps' => $gaps, 'counts' => $counts];
    }

    // ── The three decisions ───────────────────────────────────────────────────

    private function add(GroupData $g, int $storeId, string $sku, array $product, array $b, array $peers, float $median, string $tier, float $catRate, ?float $bar, array $group, array &$counts): ?array
    {
        $cfg = config('assortment');
        if ($this->inactive($product)) {
            return null;
        }
        $others   = max(1, count($g->members) - 1);
        $carrying = $b['carrying'];   // the store does not carry it, so these are all others
        if ($carrying / $others < (float) $cfg['add_min_carried_share']) {
            return null;
        }
        if ($bar !== null && $median < $bar) {
            return null;
        }
        if ($catRate <= 0) {
            $counts['skipped_no_category_sales']++;   // a whole missing category is a space decision, not v1

            return null;
        }

        $expectedRevDay   = $median * $catRate;
        $availability     = Stats::median(array_filter(array_column($peers, 'availability'), fn ($v) => $v !== null)) ?? 1.0;
        $annualRevenue    = $expectedRevDay * 365 * $availability;
        [$basis, $annual] = $this->valueBasis($annualRevenue, $product);
        $low  = $annual * (float) $cfg['incremental_share_low'];
        $high = $annual * (float) $cfg['incremental_share_high'];
        $mid  = ($low + $high) / 2;
        $unitsDay = $product['price'] > 0 ? $expectedRevDay / $product['price'] : null;

        $evidence = [
            'peer_group'           => $group['label'],
            'peer_basis'           => $group['basis'],
            'peers_carrying'       => $carrying,
            'other_stores'         => $others,
            'qualifying_peers'     => count($peers),
            'peer_units_per_day'   => round(Stats::median(array_column($peers, 'units_per_day')) ?? 0, 3),
            'peer_share_index'     => round($median, 6),
            'category'             => $product['category'],
            'store_category_revenue_per_day' => round($catRate, 2),
            'expected_revenue_per_day'       => round($expectedRevDay, 2),
            'expected_units_per_day'         => $unitsDay !== null ? round($unitsDay, 3) : null,
            'value_basis'          => $basis,
            'incremental_share'    => [(float) $cfg['incremental_share_low'], (float) $cfg['incremental_share_high']],
            'window_days'          => $g->windowDays,
        ];

        $store = $this->storeNames[$storeId] ?? "store #{$storeId}";
        $name  = $product['name'] ?: $sku;
        $lines = [
            "Carried by {$carrying} of {$others} similar stores ({$group['label']}).",
            'Similar stores that carry it sell ' . $this->num($evidence['peer_units_per_day']) . ' a day when it is in stock.',
            $unitsDay !== null
                ? "This store's {$product['category']} sales suggest about " . $this->num($unitsDay) . ' a day here.'
                : "Expected from this store's {$product['category']} sales.",
            'Worth ' . $this->range($low, $high) . ' a year in ' . ($basis === 'margin' ? 'gross margin' : 'sales') . ' after sales taken from similar products.',
        ];

        return $this->gap($storeId, $sku, $product, AssortmentGap::TYPE_ADD, $group, [$low, $mid, $high], $tier, $evidence,
            "Add {$name} at {$store}", $lines, 'range_add');
    }

    private function stockoutHidden(GroupData $g, int $storeId, string $sku, array $product, array $peers, array $shares, float $median, string $tier, float $catRate, array $group): ?array
    {
        $cfg = config('assortment');
        $own = $g->perf[$storeId][$sku] ?? null;
        if ($own === null || $own['availability'] === null) {
            return null;   // no stock history — cannot say it is out
        }
        if ($own['observations'] < (int) $cfg['stockout_min_observations'] || $own['availability'] >= (float) $cfg['stockout_max_availability']) {
            return null;
        }

        $expected = fn (?float $share) => $catRate > 0 && $share !== null
            ? $share * $catRate
            : (Stats::median(array_column($peers, 'rev_per_day')) ?? 0.0);
        $oosDays  = $own['window_days'] * (1 - $own['availability']);
        $annualise = 365 / max(1, $own['window_days']);

        $vals = [];
        foreach ([0.25, 0.5, 0.75] as $p) {
            $annualRevenue = $expected(Stats::percentile($shares, $p)) * $oosDays * $annualise;
            $vals[] = $this->valueBasis($annualRevenue, $product);
        }
        $basis = $vals[1][0];
        [$low, $mid, $high] = [$vals[0][1], $vals[1][1], $vals[2][1]];
        if ($mid <= 0) {
            return null;
        }

        $pctOut = (int) round((1 - $own['availability']) * 100);
        $evidence = [
            'peer_group'        => $group['label'],
            'peer_basis'        => $group['basis'],
            'qualifying_peers'  => count($peers),
            'availability'      => $own['availability'],
            'observations'      => $own['observations'],
            'days_out_of_stock' => round($oosDays, 1),
            'window_days'       => $own['window_days'],
            'peer_units_per_day' => round(Stats::median(array_column($peers, 'units_per_day')) ?? 0, 3),
            'own_units_per_day' => round($own['units_per_day'], 3),
            'peer_share_index'  => round($median, 6),
            'value_basis'       => $basis,
            'handoff'           => 'root_cause',
        ];

        $store = $this->storeNames[$storeId] ?? "store #{$storeId}";
        $name  = $product['name'] ?: $sku;
        $lines = [
            "Out of stock about {$pctOut}% of the time over the last {$own['window_days']} days (" . $this->num($oosDays) . ' days).',
            count($peers) . ' similar stores keep it in stock and sell ' . $this->num($evidence['peer_units_per_day']) . ' a day.',
            'Losing ' . $this->range($low, $high) . ' a year in ' . ($basis === 'margin' ? 'gross margin' : 'sales') . ' while it is off the shelf.',
            'The range is right; the stock is not — this is a stock problem, not a delist.',
        ];

        return $this->gap($storeId, $sku, $product, AssortmentGap::TYPE_STOCKOUT_HIDDEN, $group, [$low, $mid, $high], $tier, $evidence,
            "{$name} at {$store} is the right product but keeps running out", $lines, 'restore_availability');
    }

    private function delist(GroupData $g, int $storeId, string $sku, array $product, array $peers, float $median, string $tier, array $ownRank, array $group, array &$counts): ?array
    {
        $cfg = config('assortment');
        if (! $this->allowDelists) {
            return null;
        }
        $own = $g->perf[$storeId][$sku] ?? null;
        if ($own === null || $own['share'] === null || $median <= 0) {
            return null;
        }
        if ($own['carried_days'] < (int) $cfg['delist_min_carried_days']) {
            return null;   // too new to judge
        }
        if ($own['availability'] === null || $own['availability'] < (float) $cfg['delist_min_availability']) {
            return null;   // slow because it is out, or unknown — never a delist
        }
        $ratio = $own['share'] / $median;
        if ($ratio >= (float) $cfg['delist_max_peer_ratio']) {
            return null;
        }
        $rank = $ownRank[$sku] ?? null;
        if ($rank === null || ! $rank['bottom']) {
            return null;
        }
        if ($rank['kind_count'] < 2) {
            return null;   // the only product of its kind here — dropping it leaves a hole
        }
        if (isset($this->mustStock["{$storeId}|{$sku}"]) || isset($this->mustStock["*|{$sku}"])) {
            return null;
        }
        if (trim((string) ($product['season'] ?? '')) !== '') {
            return null;   // seasonal — judged in season, not v1
        }
        if ($this->tierRank($tier) < $this->tierRank((string) $cfg['delist_min_tier'])) {
            $counts['skipped_speculative_delist']++;   // delists need more certainty than adds

            return null;
        }

        $capital = $this->stockValue["{$storeId}|{$sku}"] ?? 0.0;
        $ownAnnualRevenue = $own['rev_per_day'] * 365 * $own['availability'];
        [$basis, $ownAnnual] = $this->valueBasis($ownAnnualRevenue, $product);
        // What is lost is the part of its sales that does NOT move to similar products.
        $lostLow  = $ownAnnual * (float) $cfg['incremental_share_low'];
        $lostHigh = $ownAnnual * (float) $cfg['incremental_share_high'];
        $low  = $capital - $lostHigh;
        $high = $capital - $lostLow;
        $mid  = ($low + $high) / 2;
        if ($mid <= 0) {
            return null;
        }

        $evidence = [
            'peer_group'        => $group['label'],
            'peer_basis'        => $group['basis'],
            'qualifying_peers'  => count($peers),
            'ratio_to_peers'    => round($ratio, 3),
            'own_units_per_day' => round($own['units_per_day'], 3),
            'peer_units_per_day' => round(Stats::median(array_column($peers, 'units_per_day')) ?? 0, 3),
            'availability'      => $own['availability'],
            'carried_days'      => $own['carried_days'],
            'category_rank'     => $rank['position'] . ' of ' . $rank['count'],
            'stock_value'       => round($capital, 2),
            'lost_per_year'     => [round($lostLow, 2), round($lostHigh, 2)],
            'value_basis'       => $basis,
        ];

        $store = $this->storeNames[$storeId] ?? "store #{$storeId}";
        $name  = $product['name'] ?: $sku;
        $lines = [
            'Sells at ' . $this->num($ratio) . '× the rate of ' . count($peers) . ' similar stores while in stock (in stock ' . (int) round($own['availability'] * 100) . '% of the time).',
            "Ranks {$rank['position']} of {$rank['count']} {$product['category']} products at this store.",
            'Frees ' . Money::compact($capital, $this->currency) . ' of stock; loses ' . $this->range($lostLow, $lostHigh) . ' a year in '
                . ($basis === 'margin' ? 'gross margin' : 'sales') . ' that does not move to similar products.',
        ];

        return $this->gap($storeId, $sku, $product, AssortmentGap::TYPE_DELIST, $group, [$low, $mid, $high], $tier, $evidence,
            "Consider delisting {$name} at {$store}", $lines, 'range_delist');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Qualifying peers for a SKU, excluding the store being judged. */
    private function peers(GroupData $g, int $storeId, string $sku): array
    {
        $out = [];
        foreach ($g->members as $id) {
            if ($id !== $storeId && $g->qualifies($id, $sku)) {
                $out[$id] = $g->perf[$id][$sku];
            }
        }

        return $out;
    }

    /**
     * Where each carried product sits in its category at this store (by share
     * index, lowest first), and how many carried products share its sub-category.
     *
     * @return array<string,array{position:int,count:int,bottom:bool,kind_count:int}>
     */
    private function ownCategoryRanks(GroupData $g, int $storeId): array
    {
        $byCat = $kinds = [];
        foreach ($g->perf[$storeId] ?? [] as $sku => $p) {
            $prod = $this->products[$sku] ?? null;
            if ($prod === null || $prod['category'] === null || $p['share'] === null) {
                continue;
            }
            $byCat[$prod['category']][$sku] = $p['share'];
            $kind = $prod['subcategory'] ?: $prod['category'];
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
        }
        $bottomShare = (float) config('assortment.delist_bottom_share', 0.10);
        $out = [];
        foreach ($byCat as $cat => $shares) {
            asort($shares);
            $n = count($shares);
            $cut = max(1, (int) ceil($n * $bottomShare));
            $i = 0;
            foreach (array_keys($shares) as $sku) {
                $i++;
                $prod = $this->products[$sku];
                $out[(string) $sku] = [
                    'position'   => $i,
                    'count'      => $n,
                    'bottom'     => $i <= $cut && $n >= 3,
                    'kind_count' => $kinds[$prod['subcategory'] ?: $prod['category']] ?? 1,
                ];
            }
        }

        return $out;
    }

    private function tier(array $shares): string
    {
        $t = config('assortment.tiers');
        $n = count($shares);
        $spread = Stats::spread($shares);
        if ($n <= $t['speculative']['max_peers'] || $spread === null || $spread > $t['speculative']['min_spread']) {
            return AssortmentGap::TIER_SPECULATIVE;
        }
        if ($n >= $t['established']['min_peers'] && $spread <= $t['established']['max_spread']) {
            return AssortmentGap::TIER_ESTABLISHED;
        }

        return AssortmentGap::TIER_LIKELY;
    }

    private function tierRank(string $tier): int
    {
        return match ($tier) {
            AssortmentGap::TIER_ESTABLISHED => 3,
            AssortmentGap::TIER_LIKELY      => 2,
            default                         => 1,
        };
    }

    /** Value in gross margin when price and cost are known, else in sales (flagged). @return array{0:string,1:float} */
    private function valueBasis(float $annualRevenue, array $product): array
    {
        $price = (float) ($product['price'] ?? 0);
        $cost  = (float) ($product['cost'] ?? 0);
        if ($price > 0 && $cost > 0 && $price > $cost) {
            return ['margin', $annualRevenue * (($price - $cost) / $price)];
        }

        return ['revenue', $annualRevenue];
    }

    private function inactive(array $product): bool
    {
        $status = strtolower(trim((string) ($product['status'] ?? '')));

        return $status !== '' && in_array($status, config('assortment.inactive_statuses', []), true);
    }

    private function gap(int $storeId, string $sku, array $product, string $type, array $group, array $values, string $tier, array $evidence, string $headline, array $lines, string $intent): array
    {
        [$low, $mid, $high] = array_map(fn ($v) => round((float) $v, 2), $values);
        $base       = (float) config("assortment.tiers.{$tier}.confidence", 0.4);
        $confidence = round($base * $this->qualityFactor, 4);

        $rec = new Recommendation(
            tenantId: 0,
            intentType: $intent,
            sku: $sku,
            storeId: $storeId,
            expectedValue: $mid,
            confidence: $confidence,
            risk: round(1 - $confidence, 4),
            objective: 'availability',
            source: 'assortment',
        );
        $explanation = ExplanationBuilder::from($rec)
            ->withHeadline($headline)
            ->addEvidence(...$lines)
            ->addReasons(array_merge(
                ["Confidence: {$tier} (" . ($evidence['qualifying_peers'] ?? 0) . ' comparable stores)'],
                $this->qualityReasons,
            ))
            ->build()
            ->toArray();

        return [
            'store_id'        => $storeId,
            'sku'             => $sku,
            'product_id'      => $product['id'] ?? null,
            'type'            => $type,
            'peer_group'      => $group['key'],
            'value_low'       => $low,
            'value_mid'       => $mid,
            'value_high'      => $high,
            'confidence'      => $confidence,
            'confidence_tier' => $tier,
            'evidence'        => $evidence,
            'explanation'     => $explanation,
        ];
    }

    private function range(float $low, float $high): string
    {
        $lo = Money::compact(max(0, $low), $this->currency);
        $hi = Money::compact(max(0, $high), $this->currency);

        return $lo === $hi ? "about {$lo}" : "{$lo}–{$hi}";
    }

    private function num(float $v): string
    {
        return $v >= 10 ? number_format($v, 0) : rtrim(rtrim(number_format($v, 2), '0'), '.');
    }
}
