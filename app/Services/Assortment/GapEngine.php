<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGap;
use App\Platform\Explainability\ExplanationBuilder;
use App\Platform\Intelligence\Lifecycle\ProductLifecycleService as Lifecycle;
use App\Platform\Intelligence\Substitution\TransferEstimator;
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
 *
 * v1.5 (Phase 0 + 1), on top of those rules, never instead of them:
 *   - rates are promotion-clean (GroupData); with no promotion data at all,
 *     confidence is one tier lower and every decision says why;
 *   - the product's lifecycle (platform): a new, declining or finished product
 *     is never an add; a new, emerging, seasonal or finished one never a delist;
 *     an emerging add is a "watch" at the lowest confidence;
 *   - transferable demand (platform): an add is worth its sales LESS what it
 *     takes from the store's shelf; a delist loses only what does not move to
 *     the shelf; a stockout loses only what its buyers do not buy instead. The
 *     share comes from observed stockouts and range changes, or the stated
 *     similarity assumption; with neither, the gross value is shown as the top
 *     of the range and the decision is one tier less sure.
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

    private string $delistMinTier = 'likely';

    private int $maxDelistsPerCategory = 3;

    /** @var array<string,array{state:string,reason:?string}> sku => chain-wide lifecycle */
    private array $lifecycle = [];

    private bool $hasPromotionData = true;

    /** @var array<string,array<string,array<string,mixed>>> from_sku => to_sku => observed transfer (current chunk) */
    private array $observed = [];

    private ?TransferEstimator $transfers = null;

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
        string $delistMinTier = 'likely',
        int $maxDelistsPerCategory = 3,
        array $lifecycle = [],
        bool $hasPromotionData = true,
    ): self {
        $this->lifecycle             = $lifecycle;
        $this->hasPromotionData      = $hasPromotionData;
        $this->transfers             = new TransferEstimator();
        $this->delistMinTier         = $delistMinTier;
        $this->maxDelistsPerCategory = max(1, $maxDelistsPerCategory);
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
     * What the decisions need across the whole group before any chunk is judged:
     * the add bar per category (P40 of the group's median share index per
     * product) and, per judged store, where each carried product ranks in its
     * category (for delists).
     *
     * @param  array<string,array<string,mixed>>  $benchmark  sku => benchmark (whole group)
     * @param  array<int,array<string,float>>  $shares  store => sku => own share index (carried, non-null)
     * @param  array<int,int>  $targets
     * @return array{bars: array<string,?float>, ranks: array<int,array<string,array<string,mixed>>>}
     */
    public function prepare(array $benchmark, array $shares, array $targets): array
    {
        $cfg = config('assortment');
        $byCategory = [];
        foreach ($benchmark as $sku => $b) {
            $cat = $this->products[$sku]['category'] ?? null;
            if ($cat !== null && $b['qualifying'] >= $cfg['min_qualifying_peers'] && $b['share_index_median'] !== null) {
                $byCategory[$cat][] = $b['share_index_median'];
            }
        }
        $bars = array_map(fn (array $v) => Stats::percentile($v, (float) $cfg['add_rate_percentile']), $byCategory);

        $ranks = [];
        foreach ($targets as $storeId) {
            $ranks[$storeId] = $this->ownCategoryRanks($shares[$storeId] ?? []);
        }

        return ['bars' => $bars, 'ranks' => $ranks, 'shelf' => []];
    }

    /**
     * What each judged store carries, by category — the shelf that demand moves
     * to (or is taken from).
     *
     * @param  array<int,array<string,true>>  $carried  store => sku => true
     * @return array<int,array<string,array<int,string>>>
     */
    public function shelves(array $carried): array
    {
        $out = [];
        foreach ($carried as $storeId => $skus) {
            foreach (array_keys($skus) as $sku) {
                $cat = $this->products[$sku]['category'] ?? null;
                if ($cat !== null) {
                    $out[$storeId][$cat][] = (string) $sku;
                }
            }
        }

        return $out;
    }

    /** Observed transfers for the products of the chunk being judged. */
    public function withTransfers(array $observed): self
    {
        $this->observed = $observed;

        return $this;
    }

    /**
     * The decisions for the stores judged against this group, for the SKUs of
     * one chunk.
     *
     * @param  array<int,int>  $targets
     * @param  array<string,array<string,mixed>>  $benchmark  whole group
     * @param  array{bars: array<string,?float>, ranks: array<int,array<string,array<string,mixed>>>}  $prepared
     * @param  array<string,mixed>  $group  key, label, basis
     * @param  array<string,int>  $counts
     * @return array<int,array<string,mixed>>
     */
    public function evaluateChunk(GroupData $g, array $targets, array $benchmark, array $prepared, array $group, array &$counts): array
    {
        $cfg  = config('assortment');
        $gaps = [];

        foreach ($targets as $storeId) {
            $ownRank = $prepared['ranks'][$storeId] ?? [];

            foreach ($g->skus as $sku) {
                $b = $benchmark[$sku] ?? null;
                $product = $this->products[$sku] ?? null;
                if ($b === null || $product === null || $product['category'] === null) {
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

                $shelf = $prepared['shelf'][$storeId][$product['category']] ?? [];
                if (! $g->carries($storeId, $sku)) {
                    $gap = $this->add($g, $storeId, $sku, $product, $b, $peers, $median, $tier, $catRate, $prepared['bars'][$product['category']] ?? null, $group, $counts, $shelf);
                } else {
                    $gap = $this->stockoutHidden($g, $storeId, $sku, $product, $peers, $shares, $median, $tier, $catRate, $group, $shelf, $counts)
                        ?? $this->delist($g, $storeId, $sku, $product, $peers, $median, $tier, $ownRank, $group, $counts, $shelf);
                }
                if ($gap !== null) {
                    $gaps[] = $gap;
                }
            }
        }

        return $gaps;
    }

    /**
     * Range health for the group once every chunk is judged.
     *
     * @param  array<string,int>  $stockoutsByCategory
     * @return array<int,array<string,mixed>>
     */
    public function finalize(array $targets, array $benchmark, array $group, array $shares, array $carried, array $stockoutsByCategory): array
    {
        return $this->health($targets, $benchmark, $group, $shares, $carried, $stockoutsByCategory);
    }

    public function categoryOf(string $sku): ?string
    {
        return $this->products[$sku]['category'] ?? null;
    }

    // ── The three decisions ───────────────────────────────────────────────────

    private function add(GroupData $g, int $storeId, string $sku, array $product, array $b, array $peers, float $median, string $tier, float $catRate, ?float $bar, array $group, array &$counts, array $shelf = []): ?array
    {
        $cfg = config('assortment');
        if ($this->inactive($product)) {
            return null;
        }
        $life = $this->lifecycle[$sku]['state'] ?? null;
        if (in_array($life, [Lifecycle::NEW, Lifecycle::DECLINING, Lifecycle::END_OF_LIFE], true)) {
            $counts['skipped_lifecycle'] = ($counts['skipped_lifecycle'] ?? 0) + 1;

            return null;   // too new to judge, fading everywhere, or finished
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
        // What it takes from the shelf is not new money: incremental = gross × (1 − taken).
        $transfer = $this->transfer($sku, $shelf);
        [$low, $mid, $high] = $this->net($annual, $transfer);
        $unitsDay = $product['price'] > 0 ? $expectedRevDay / $product['price'] : null;
        [$tier, $caps] = $this->capTier($tier, $transfer);
        if ($life === Lifecycle::EMERGING) {
            $tier = AssortmentGap::TIER_SPECULATIVE;
            $caps[] = 'Still growing across the chain: a watch, at the lowest confidence.';
        }

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
            'gross_per_year'       => round($annual, 2),
            'gross_sales_per_year' => round($annualRevenue, 2),
            'margin_rate'          => $this->marginRate($product),
            'taken_per_year'       => $this->takenRange($annual, $transfer),
            // In sales, the unit the result is measured in: the category's sales should rise by this much.
            'expected_sales_change_per_year' => $transfer['mid'] === null ? null : round($annualRevenue * (1 - $transfer['mid']), 2),
            'transfer'             => $this->compactTransfer($transfer),
            'incremental_share'    => $transfer['mid'] === null ? null : [round(1 - $transfer['high'], 4), round(1 - $transfer['low'], 4)],
            'lifecycle'            => $life,
            'promo_share_peers'    => $this->peerPromoShare($peers),
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
            $this->takenLine($annual, $transfer, $basis),
            'Worth ' . $this->range($low, $high) . ' a year in ' . ($basis === 'margin' ? 'gross margin' : 'sales')
                . ($transfer['mid'] === null ? ' before substitution.' : ' after sales taken from similar products.'),
        ];
        if ($life === Lifecycle::EMERGING) {
            $lines[] = 'Still growing across the chain (' . ($this->lifecycle[$sku]['reason'] ?? 'under 26 weeks old') . '): treat it as a watch.';
        }

        return $this->gap($storeId, $sku, $product, AssortmentGap::TYPE_ADD, $group, [$low, $mid, $high], $tier, $evidence,
            ($life === Lifecycle::EMERGING ? 'Watch: add ' : 'Add ') . "{$name} at {$store}", $lines, 'range_add', $caps);
    }

    private function stockoutHidden(GroupData $g, int $storeId, string $sku, array $product, array $peers, array $shares, float $median, string $tier, float $catRate, array $group, array $shelf = [], array &$counts = []): ?array
    {
        $cfg = config('assortment');
        if (($this->lifecycle[$sku]['state'] ?? null) === Lifecycle::END_OF_LIFE) {
            return null;   // a finished product is not expected on the shelf
        }
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
        $revenueMid = 0.0;
        foreach ([0.25, 0.5, 0.75] as $p) {
            $annualRevenue = $expected(Stats::percentile($shares, $p)) * $oosDays * $annualise;
            $vals[] = $this->valueBasis($annualRevenue, $product);
            if ($p === 0.5) {
                $revenueMid = $annualRevenue;
            }
        }
        $basis = $vals[1][0];
        [$grossLow, $grossMid, $grossHigh] = [$vals[0][1], $vals[1][1], $vals[2][1]];
        // While it is out, part of its demand is bought as something else on the shelf: only the rest is lost.
        $transfer = $this->transfer($sku, array_values(array_diff($shelf, [$sku])));
        if ($transfer['mid'] === null) {
            [$low, $mid, $high] = [0.0, $grossMid / 2, $grossHigh];
        } else {
            [$low, $mid, $high] = [$grossLow * (1 - $transfer['high']), $grossMid * (1 - $transfer['mid']), $grossHigh * (1 - $transfer['low'])];
        }
        if ($mid <= 0) {
            return null;
        }
        [$tier, $caps] = $this->capTier($tier, $transfer);

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
            'gross_per_year'    => round($grossMid, 2),
            'gross_sales_per_year' => round($revenueMid, 2),
            'margin_rate'       => $this->marginRate($product),
            'expected_units_per_day' => round(Stats::median(array_column($peers, 'units_per_day')) ?? 0, 3),
            'moved_per_year'    => $transfer['mid'] === null ? null : [round($grossMid * $transfer['low'], 2), round($grossMid * $transfer['high'], 2)],
            // Measured on the product's own sales: kept in stock, all of its out-of-stock demand comes back to it
            // (the moved part from substitutes, the lost part new to the store).
            'expected_sales_change_per_year' => round($revenueMid, 2),
            'transfer'          => $this->compactTransfer($transfer),
            'lifecycle'         => $this->lifecycle[$sku]['state'] ?? null,
            'handoff'           => 'root_cause',
        ];

        $store = $this->storeNames[$storeId] ?? "store #{$storeId}";
        $name  = $product['name'] ?: $sku;
        $lines = [
            "Out of stock about {$pctOut}% of the time over the last {$own['window_days']} days (" . $this->num($oosDays) . ' days).',
            count($peers) . ' similar stores keep it in stock and sell ' . $this->num($evidence['peer_units_per_day']) . ' a day.',
            $this->movedLine($transfer, 'while it is out'),
            'Losing ' . $this->range($low, $high) . ' a year in ' . ($basis === 'margin' ? 'gross margin' : 'sales') . ' while it is off the shelf'
                . ($transfer['mid'] === null ? ' (before substitution).' : ', after what its buyers take instead.'),
            'The range is right; the stock is not — this is a stock problem, not a delist.',
        ];

        return $this->gap($storeId, $sku, $product, AssortmentGap::TYPE_STOCKOUT_HIDDEN, $group, [$low, $mid, $high], $tier, $evidence,
            "{$name} at {$store} is the right product but keeps running out", array_values(array_filter($lines)), 'restore_availability', $caps);
    }

    private function delist(GroupData $g, int $storeId, string $sku, array $product, array $peers, float $median, string $tier, array $ownRank, array $group, array &$counts, array $shelf = []): ?array
    {
        $cfg = config('assortment');
        if (! $this->allowDelists) {
            return null;
        }
        $life = $this->lifecycle[$sku]['state'] ?? null;
        if (in_array($life, [Lifecycle::NEW, Lifecycle::EMERGING, Lifecycle::SEASONAL, Lifecycle::END_OF_LIFE], true)) {
            return null;   // too young, judged in season, or already finished (clean-up, not a delist)
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
        $transfer = $this->transfer($sku, array_values(array_diff($shelf, [$sku])));
        [$tier, $caps] = $this->capTier($tier, $transfer);
        if ($this->tierRank($tier) < $this->tierRank($this->delistMinTier)) {
            $counts['skipped_speculative_delist']++;   // delists need more certainty than adds

            return null;
        }

        $capital = $this->stockValue["{$storeId}|{$sku}"] ?? 0.0;
        $ownAnnualRevenue = $own['rev_per_day'] * 365 * $own['availability'];
        [$basis, $ownAnnual] = $this->valueBasis($ownAnnualRevenue, $product);
        // What is lost is the part of its sales that does NOT move to the rest of the shelf.
        [$lostLow, $lostHigh] = $transfer['mid'] === null
            ? [0.0, $ownAnnual]
            : [$ownAnnual * (1 - $transfer['high']), $ownAnnual * (1 - $transfer['low'])];
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
            'current_per_year'  => round($ownAnnual, 2),
            'current_sales_per_year' => round($ownAnnualRevenue, 2),
            'margin_rate'       => $this->marginRate($product),
            // The category's sales should fall only by what does not move to the shelf.
            'expected_sales_change_per_year' => $transfer['mid'] === null ? null : round(-$ownAnnualRevenue * (1 - $transfer['mid']), 2),
            'moved_per_year'    => $transfer['mid'] === null ? null : [round($ownAnnual * $transfer['low'], 2), round($ownAnnual * $transfer['high'], 2)],
            'lost_per_year'     => [round($lostLow, 2), round($lostHigh, 2)],
            'transfer'          => $this->compactTransfer($transfer),
            'lifecycle'         => $life,
            'promo_share'       => $own['promo_share'] ?? null,
            'value_basis'       => $basis,
        ];

        $store = $this->storeNames[$storeId] ?? "store #{$storeId}";
        $name  = $product['name'] ?: $sku;
        $lines = [
            'Sells at ' . $this->num($ratio) . '× the rate of ' . count($peers) . ' similar stores while in stock (in stock ' . (int) round($own['availability'] * 100) . '% of the time).',
            "Ranks {$rank['position']} of {$rank['count']} {$product['category']} products at this store.",
            $this->movedLine($transfer, 'if it goes'),
            'Frees ' . Money::compact($capital, $this->currency) . ' of stock; loses ' . $this->range($lostLow, $lostHigh) . ' a year in '
                . ($basis === 'margin' ? 'gross margin' : 'sales') . ($transfer['mid'] === null
                    ? ' at most (where its buyers would go could not be estimated).'
                    : ' that does not move to similar products.'),
        ];

        return $this->gap($storeId, $sku, $product, AssortmentGap::TYPE_DELIST, $group, [$low, $mid, $high], $tier, $evidence,
            "Consider delisting {$name} at {$store}", array_values(array_filter($lines)), 'range_delist', $caps);
    }

    /**
     * Range health per category for the stores judged here:
     *   coverage  — of the products most peers carry (≥ 50%), the share each
     *               store carries, averaged over the stores;
     *   tail      — share of carried products selling under 0.3× their peers;
     *   stockout  — share of carried products that are stockout-hidden.
     *
     * @return array<int,array<string,mixed>>
     */
    private function health(array $targets, array $benchmark, array $group, array $shares, array $carriedSet, array $out): array
    {
        $core = $carried = $tail = [];
        foreach ($benchmark as $sku => $b) {
            $cat = $this->products[$sku]['category'] ?? null;
            if ($cat === null) {
                continue;
            }
            $median = $b['share_index_median'];
            foreach ($targets as $storeId) {
                $has = isset($carriedSet[$storeId][$sku]);
                if ($b['carried_share'] >= 0.5) {
                    $core[$cat][$storeId]['of'] = ($core[$cat][$storeId]['of'] ?? 0) + 1;
                    $core[$cat][$storeId]['has'] = ($core[$cat][$storeId]['has'] ?? 0) + ($has ? 1 : 0);
                }
                if ($has) {
                    $carried[$cat] = ($carried[$cat] ?? 0) + 1;
                    $share = $shares[$storeId][$sku] ?? null;
                    if ($median && $share !== null && $share < 0.3 * $median) {
                        $tail[$cat] = ($tail[$cat] ?? 0) + 1;
                    }
                }
            }
        }
        $rows = [];
        foreach ($carried as $cat => $n) {
            $cov = collect($core[$cat] ?? [])->filter(fn ($c) => ($c['of'] ?? 0) > 0)->map(fn ($c) => $c['has'] / $c['of']);
            $rows[] = [
                'group'    => $group['label'],
                'category' => $cat,
                'stores'   => count($targets),
                'carried'  => $n,
                'coverage' => $cov->isEmpty() ? null : round($cov->avg(), 3),
                'tail'     => round(($tail[$cat] ?? 0) / max(1, $n), 3),
                'stockout' => round(($out[$cat] ?? 0) / max(1, $n), 3),
            ];
        }

        return $rows;
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
    private function ownCategoryRanks(array $ownShares): array
    {
        $byCat = $kinds = [];
        foreach ($ownShares as $sku => $share) {
            $prod = $this->products[$sku] ?? null;
            if ($prod === null || $prod['category'] === null || $share === null) {
                continue;
            }
            $byCat[$prod['category']][$sku] = $share;
            $kind = $prod['subcategory'] ?: $prod['category'];
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
        }
        $bottomShare = (float) config('assortment.delist_bottom_share', 0.10);
        $out = [];
        foreach ($byCat as $shares) {
            asort($shares);
            $n = count($shares);
            $cut = max(1, (int) ceil($n * $bottomShare));
            $i = 0;
            foreach (array_keys($shares) as $sku) {
                $i++;
                if ($i > $cut || $n < 3) {
                    break;   // only the bottom of the category can be a delist — keep just those
                }
                $prod = $this->products[$sku];
                $out[(string) $sku] = [
                    'position'   => $i,
                    'count'      => $n,
                    'bottom'     => true,
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

    /** Gross margin as a share of the selling price, or null when price or cost is missing. */
    private function marginRate(array $product): ?float
    {
        $price = (float) ($product['price'] ?? 0);
        $cost  = (float) ($product['cost'] ?? 0);

        return $price > 0 && $cost > 0 && $price > $cost ? round(($price - $cost) / $price, 4) : null;
    }

    private function inactive(array $product): bool
    {
        $status = strtolower(trim((string) ($product['status'] ?? '')));

        return $status !== '' && in_array($status, config('assortment.inactive_statuses', []), true);
    }

    private function gap(int $storeId, string $sku, array $product, string $type, array $group, array $values, string $tier, array $evidence, string $headline, array $lines, string $intent, array $caps = []): array
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
                $caps,
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
            // Encoded now: a decision is kept as a compact string until it is saved.
            'evidence'        => json_encode($evidence, JSON_UNESCAPED_UNICODE),
            'explanation'     => json_encode($explanation, JSON_UNESCAPED_UNICODE),
        ];
    }

    // ── Transferable demand (v1.5) ────────────────────────────────────────────

    /** Where this product's buyers go on the store's shelf (or come from, for an add). */
    private function transfer(string $sku, array $shelf): array
    {
        return ($this->transfers ??= new TransferEstimator())->estimate($sku, $shelf, $this->products, $this->observed[$sku] ?? []);
    }

    /** Net of what is taken from the shelf; gross as the top of the range when that cannot be estimated. */
    private function net(float $gross, array $t): array
    {
        if ($t['mid'] === null) {
            return [0.0, $gross / 2, $gross];
        }

        return [$gross * (1 - $t['high']), $gross * (1 - $t['mid']), $gross * (1 - $t['low'])];
    }

    private function takenRange(float $gross, array $t): ?array
    {
        return $t['mid'] === null ? null : [round($gross * $t['low'], 2), round($gross * $t['high'], 2)];
    }

    /**
     * One tier lower (never below speculative) for each thing that makes the
     * figure less sure, with the reason in words.
     *
     * @return array{0:string,1:array<int,string>}
     */
    private function capTier(string $tier, array $transfer): array
    {
        $caps = [];
        if (! $this->hasPromotionData) {
            $caps[] = 'No promotion data: promotional sales may be counted as everyday demand.';
        }
        if ($transfer['mid'] === null) {
            $caps[] = 'Substitution could not be estimated: ' . rtrim((string) $transfer['note'], '.') . '.';
        }
        foreach ($caps as $_) {
            $tier = match ($tier) {
                AssortmentGap::TIER_ESTABLISHED => AssortmentGap::TIER_LIKELY,
                default                         => AssortmentGap::TIER_SPECULATIVE,
            };
        }

        return [$tier, $caps];
    }

    /** The transfer figures kept with the decision (the learning record uses them later). */
    private function compactTransfer(array $t): array
    {
        return [
            'basis'      => $t['basis'],
            'share'      => $t['mid'] === null ? null : [$t['low'], $t['mid'], $t['high']],
            'tier'       => $t['tier'],
            'stores'     => $t['stores'],
            'store_days' => $t['store_days'],
            'events'     => $t['events'],
            'pairs'      => array_map(fn ($p) => array_filter([
                'sku' => $p['sku'], 'name' => $p['name'], 'share' => [$p['low'], $p['mid'], $p['high']],
                'basis' => $p['basis'], 'evidence' => $p['evidence'] ?? null, 'shared' => $p['shared'] ?? null,
                'stores' => $p['stores'] ?: null, 'store_days' => $p['store_days'] ?: null, 'events' => $p['events'] ?: null,
            ], fn ($v) => $v !== null), $t['pairs']),
            'note'       => $t['note'],
            'version'    => $t['version'],
        ];
    }

    private function takenLine(float $gross, array $t, string $basis): ?string
    {
        $what = $basis === 'margin' ? 'gross margin' : 'sales';
        if ($t['mid'] === null) {
            return 'Gross ' . Money::compact($gross, $this->currency) . " a year in {$what}; how much of it would come from products already on the shelf could not be estimated.";
        }
        if ($t['mid'] <= 0) {
            return 'Nothing similar is on this shelf, so its sales would be new to the store.';
        }

        return 'About ' . $this->pct($t['low'], $t['high']) . ' of its sales would come from products already on the shelf'
            . $this->mostly($t) . ' — ' . $this->source($t) . '.';
    }

    private function movedLine(array $t, string $when): ?string
    {
        if ($t['mid'] === null) {
            return null;
        }
        if ($t['mid'] <= 0) {
            return "Nothing similar is on this shelf: its buyers have nothing to switch to {$when}.";
        }

        return 'About ' . $this->pct($t['low'], $t['high']) . " of its buyers would switch to other products {$when}"
            . $this->mostly($t) . ' — ' . $this->source($t) . '.';
    }

    private function mostly(array $t): string
    {
        $names = array_slice(array_column($t['pairs'], 'name'), 0, 2);

        return $names === [] ? '' : ' (mostly ' . implode(' and ', $names) . ')';
    }

    private function source(array $t): string
    {
        if ($t['basis'] === TransferEstimator::BASIS_OBSERVED) {
            $bits = [];
            if ($t['store_days'] > 0) {
                $bits[] = number_format($t['store_days']) . ' store-days of stockouts';
            }
            if ($t['events'] > 0) {
                $bits[] = number_format($t['events']) . ' range changes';
            }

            return 'estimated from ' . implode(' and ', $bits) . ' across ' . $t['stores'] . ' comparable stores';
        }
        $shared = $t['pairs'][0]['shared'] ?? [];

        return 'assumed from product similarity' . ($shared !== [] ? ' (same ' . $this->joinWords($shared) . ')' : '') . ', not yet observed';
    }

    private function pct(float $low, float $high): string
    {
        $lo = (int) round($low * 100);
        $hi = (int) round($high * 100);

        return $lo === $hi ? "{$lo}%" : "{$lo}–{$hi}%";
    }

    private function joinWords(array $w): string
    {
        return count($w) <= 1 ? implode('', $w) : implode(', ', array_slice($w, 0, -1)) . ' and ' . end($w);
    }

    /** The median share of peers' units sold on promotion (evidence only). */
    private function peerPromoShare(array $peers): ?float
    {
        $v = array_values(array_filter(array_column($peers, 'promo_share'), fn ($x) => $x !== null));

        return $v === [] ? null : round((float) Stats::median($v), 4);
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
