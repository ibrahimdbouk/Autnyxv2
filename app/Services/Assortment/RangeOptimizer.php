<?php

namespace App\Services\Assortment;

use App\Models\AssortmentPlan;
use App\Platform\Objectives\MultiObjectiveScorer;

/**
 * v1.5 Phase 2 — the range optimiser for ONE store × category. Deterministic,
 * explainable step by step, and bounded (a shelf has at most a handful of
 * candidate changes: the engine keeps the best few per store × category).
 *
 * It decides how the engine's candidates work TOGETHER; it never invents one.
 *
 *   1. Start from today's range. Products that cannot move stay: must-stock,
 *      new / emerging / seasonal, and stockout recoveries (a stock problem is
 *      fixed, never delisted — every recovery is in the plan).
 *   2. Hard limits: the most products the shelf may hold (today's count plus
 *      the category's room, or a set maximum) and the fewest (today's count less
 *      a fifth). If they cannot be met with the delists the evidence supports,
 *      the plan says so and names the conflict; nothing is broken silently.
 *   3. Each step takes the single best move — an add, a delist, or (when the
 *      shelf is full) a swap — scored against the category's objective after
 *      transferable demand on the shelf AS IT STANDS after the moves already
 *      made. So once A is in, a near-identical B takes most of its sales from A
 *      and drops out; once C is delisted, a similar D loses more of its buyers
 *      and is kept. It stops when no move adds value, or a limit or the sales
 *      floor would be broken.
 *   4. A swap pass tries each chosen add against each one left out.
 *
 * Every move keeps its reason; every candidate left out keeps why.
 * Figures are per year: sales and margin as low / mid / high, stock at cost.
 */
final class RangeOptimizer
{
    public const VERSION = 'opt-2026.10.1';

    public const STOCK_COVER_DAYS = 21;   // stock an added product needs on the shelf, in days of its sales

    private const EPS = 1e-6;

    public function __construct(private MultiObjectiveScorer $scorer) {}

    /**
     * @param  array{skus: array<string,array<string,mixed>>, baseline: array{sales:float,margin:float,stock:float,count:int}}  $shelf
     *         skus: sku => [sales, margin_rate, stock, protected (reason|null), name]
     * @param  array<int,array<string,mixed>>  $candidates  kind add|delist|recover, gap_id, sku, name, tier, confidence,
     *         gross_sales (add/recover), current_sales (delist), margin_rate, stock_value (delist), units_per_day, cost, reason
     * @param  array{role:string, objective:string, room:float, sales_floor:?float, max_size:?int}  $strategy
     * @param  callable(string $sku, array $shelfSkus): array  $transfer  low/mid/high share of the product's demand that the shelf takes (null = unknown)
     * @return array<string,mixed>
     */
    public function optimise(array $shelf, array $candidates, array $strategy, callable $transfer): array
    {
        $base = $shelf['baseline'];
        $n0 = (int) $base['count'];
        [$maxN, $minN] = $this->limits($n0, $strategy);
        $weights = CategoryStrategy::OBJECTIVES[$strategy['objective']]['weights'] ?? CategoryStrategy::OBJECTIVES['balanced']['weights'];
        $factors = CategoryStrategy::ROLE_FACTORS[$strategy['role']] ?? ['add' => 1.0, 'delist' => 1.0];
        $floor = $strategy['sales_floor'];

        usort($candidates, fn ($a, $b) => [$a['kind'], $a['sku']] <=> [$b['kind'], $b['sku']]);
        $adds = array_values(array_filter($candidates, fn ($c) => $c['kind'] === AssortmentPlan::ADD));
        $delists = array_values(array_filter($candidates, fn ($c) => $c['kind'] === AssortmentPlan::DELIST
            && empty($shelf['skus'][$c['sku']]['protected'])));
        $recovers = array_values(array_filter($candidates, fn ($c) => $c['kind'] === AssortmentPlan::RECOVER));

        $current = array_keys($shelf['skus']);
        $protected = array_filter(array_map(fn ($p) => $p['protected'] ?? null, $shelf['skus']));
        $knownMargin = $this->shelfMargin($shelf['skus']);
        // Without cost prices margin is not invented: "margin" then counts sales (and the plan says so).
        $marginShelf = $knownMargin ?? 1.0;

        $constraints = [
            'current_count' => $n0, 'max_size' => $maxN, 'min_size' => $minN, 'room' => (float) $strategy['room'],
            'set_max' => $strategy['max_size'], 'sales_floor' => $floor, 'objective' => $strategy['objective'],
            'role' => $strategy['role'], 'bound' => [],
        ];

        // ── Feasibility ───────────────────────────────────────────────────────
        $mustRemove = max(0, $n0 - $maxN);
        $mustStay = count(array_unique(array_merge(array_keys($protected), array_column($recovers, 'sku'))));
        if ($mustStay > $maxN) {
            return $this->infeasible($shelf, $recovers, $protected, $constraints, sprintf(
                'No feasible range under the current limits: %d products must stay (must-stock, new, seasonal or being restocked) but the shelf may hold at most %d.',
                $mustStay, $maxN));
        }
        if ($mustRemove > count($delists)) {
            return $this->infeasible($shelf, $recovers, $protected, $constraints, sprintf(
                'No feasible range under the current limits: the shelf holds %d products and may hold at most %d, but only %d product(s) have the evidence to be delisted.',
                $n0, $maxN, count($delists)));
        }

        // ── Greedy moves ──────────────────────────────────────────────────────
        $state = ['shelf' => $current, 'sales' => 0.0, 'count' => $n0];
        $moves = [];
        $left = [];
        $chosenAdds = $chosenDelists = [];

        while (true) {
            $options = [];
            $forced = $state['count'] > $maxN;
            foreach ($adds as $a) {
                if (isset($chosenAdds[$a['sku']]) || $forced) {
                    continue;
                }
                if ($state['count'] + 1 <= $maxN) {
                    $options[] = $this->option([$a], [], $state['shelf'], $base, $weights, $factors, $marginShelf, $transfer);
                } else {
                    foreach ($delists as $d) {
                        if (! isset($chosenDelists[$d['sku']]) && $state['count'] - 1 >= $minN - 1) {
                            $options[] = $this->option([$a], [$d], $state['shelf'], $base, $weights, $factors, $marginShelf, $transfer);
                        }
                    }
                }
            }
            foreach ($delists as $d) {
                if (! isset($chosenDelists[$d['sku']]) && $state['count'] - 1 >= $minN) {
                    $options[] = $this->option([], [$d], $state['shelf'], $base, $weights, $factors, $marginShelf, $transfer);
                }
            }
            if ($options === []) {
                break;
            }
            // The sales floor: a move that would take the plan's sales below it is not taken.
            $allowed = array_values(array_filter($options, fn ($o) => $floor === null || $base['sales'] <= 0
                || ($state['sales'] + $o['sales'][1]) / $base['sales'] >= $floor - self::EPS));
            if ($allowed !== $options && $floor !== null) {
                $constraints['bound'][] = 'sales_floor';
            }
            usort($allowed, fn ($x, $y) => [$y['score'], $x['key']] <=> [$x['score'], $y['key']]);
            $best = $allowed[0] ?? null;
            if ($best === null || (! $forced && $best['score'] <= self::EPS)) {
                break;
            }
            $best['step'] = count($moves) + 1;
            $best['forced'] = $forced;
            $moves[] = $best;
            foreach ($best['adds'] as $a) {
                $chosenAdds[$a['sku']] = true;
                $state['shelf'][] = $a['sku'];
            }
            foreach ($best['delists'] as $d) {
                $chosenDelists[$d['sku']] = true;
                $state['shelf'] = array_values(array_diff($state['shelf'], [$d['sku']]));
            }
            $state['sales'] += $best['sales'][1];
            $state['count'] += count($best['adds']) - count($best['delists']);
        }

        // ── Swap pass: a left-out add that beats a chosen one takes its place ─
        foreach ($moves as $i => $m) {
            if (count($m['adds']) !== 1) {
                continue;
            }
            $without = array_values(array_diff($state['shelf'], [$m['adds'][0]['sku']]));
            foreach ($adds as $b) {
                if (isset($chosenAdds[$b['sku']])) {
                    continue;
                }
                $alt = $this->option([$b], $m['delists'], $this->withBack($without, $m['delists']), $base, $weights, $factors, $marginShelf, $transfer);
                $cur = $this->option($m['adds'], $m['delists'], $this->withBack($without, $m['delists']), $base, $weights, $factors, $marginShelf, $transfer);
                if ($alt['score'] > $cur['score'] + self::EPS) {
                    unset($chosenAdds[$m['adds'][0]['sku']]);
                    $chosenAdds[$b['sku']] = true;
                    $state['shelf'] = array_merge($without, [$b['sku']]);
                    $alt['step'] = $m['step'];
                    $alt['forced'] = false;
                    $alt['swapped_for'] = $m['adds'][0]['sku'];
                    $moves[$i] = $alt;
                    $without = array_values(array_diff($state['shelf'], [$b['sku']]));
                }
            }
        }

        // ── The chosen moves, re-measured in order on the final choice ────────
        $shelfNow = $current;
        foreach ($moves as $i => $m) {
            $o = $this->option($m['adds'], $m['delists'], $shelfNow, $base, $weights, $factors, $marginShelf, $transfer);
            $moves[$i] = array_merge($o, array_intersect_key($m, array_flip(['step', 'forced', 'swapped_for'])));
            $shelfNow = array_values(array_diff(array_merge($shelfNow, array_column($m['adds'], 'sku')), array_column($m['delists'], 'sku')));
        }

        // ── Why each candidate left out was left out ──────────────────────────
        $finalCount = $n0 + count($chosenAdds) - count($chosenDelists);
        foreach ($adds as $a) {
            if (! isset($chosenAdds[$a['sku']])) {
                $o = $this->option([$a], [], $state['shelf'], $base, $weights, $factors, $marginShelf, $transfer);
                $why = $o['score'] <= self::EPS ? 'no_value' : ($finalCount >= $maxN ? 'shelf_full' : 'sales_floor');
                $left[] = $this->leftOut($a, $why, $o);
                if ($why === 'shelf_full') {
                    $constraints['bound'][] = 'max_size';
                }
            }
        }
        foreach ($delists as $d) {
            if (! isset($chosenDelists[$d['sku']])) {
                $o = $this->option([], [$d], $state['shelf'], $base, $weights, $factors, $marginShelf, $transfer);
                $why = $o['score'] <= self::EPS ? 'no_value' : ($finalCount <= $minN ? 'min_size' : 'sales_floor');
                $left[] = $this->leftOut($d, $why, $o);
                if ($why === 'min_size') {
                    $constraints['bound'][] = 'min_size';
                }
            }
        }
        // ── Recoveries: always in; a stock fix, not a range change ────────────
        $recoverRows = $this->recoverRows($recovers, $state['shelf'], $marginShelf, $transfer);

        $changes = array_merge(array_map(fn ($m) => $this->change($m, $base), $moves), $recoverRows, $this->protectedRows($shelf));
        $impact = $this->impact($changes, $base, $finalCount);

        $acting = array_filter($changes, fn ($c) => $c['kind'] !== AssortmentPlan::PROTECT);
        $tiers = array_column($acting, 'tier');
        $rank = ['established' => 3, 'likely' => 2, 'speculative' => 1];
        usort($tiers, fn ($x, $y) => ($rank[$x] ?? 1) <=> ($rank[$y] ?? 1));

        $constraints['bound'] = array_values(array_unique($constraints['bound']));

        return [
            'feasible'        => true,
            'reason'          => null,
            'current_count'   => $n0,
            'proposed_count'  => $finalCount,
            'changes'         => array_values($changes),
            'left_out'        => $left,
            'impact'          => $impact,
            'constraints'     => $constraints,
            'confidence'      => $acting === [] ? 0.0 : min(array_column($acting, 'confidence')),
            'confidence_tier' => $tiers[0] ?? 'speculative',
            'value_mid'       => $impact['sales'][1],
            'margin_known'    => $knownMargin !== null,
            'version'         => self::VERSION,
        ];
    }

    /**
     * Phase 3 — the Decision Studio: the changes a PERSON picked, in the order
     * picked, each valued on the shelf as it stands after the ones before it,
     * with the same transferable-demand figures and objective score the
     * optimiser uses. Nothing is chosen for them. Limits that would be broken
     * are reported (a what-if may explore past them); a plan cannot be made
     * from a result that breaks one.
     *
     * @param  array<int,array<string,mixed>>  $moves  ordered candidates (kind add | delist), shaped as for optimise()
     * @param  array<int,array<string,mixed>>  $recovers  stockout fixes kept in the plan
     * @return array<string,mixed>  the optimise() shape, plus violations and skipped
     */
    public function evaluate(array $shelf, array $moves, array $recovers, array $strategy, callable $transfer): array
    {
        $base = $shelf['baseline'];
        $n0 = (int) $base['count'];
        [$maxN, $minN] = $this->limits($n0, $strategy);
        $weights = CategoryStrategy::OBJECTIVES[$strategy['objective']]['weights'] ?? CategoryStrategy::OBJECTIVES['balanced']['weights'];
        $factors = CategoryStrategy::ROLE_FACTORS[$strategy['role']] ?? ['add' => 1.0, 'delist' => 1.0];
        $knownMargin = $this->shelfMargin($shelf['skus']);
        $marginShelf = $knownMargin ?? 1.0;

        $shelfNow = array_map('strval', array_keys($shelf['skus']));
        $count = $n0;
        $rows = $skipped = [];
        foreach ($moves as $mv) {
            $sku = (string) $mv['sku'];
            $on = in_array($sku, $shelfNow, true);
            if ($mv['kind'] === AssortmentPlan::ADD) {
                if ($on) {
                    $skipped[] = ['sku' => $sku, 'why' => 'Already on the shelf.'];

                    continue;
                }
                $o = $this->option([$mv], [], $shelfNow, $base, $weights, $factors, $marginShelf, $transfer);
                $shelfNow[] = $sku;
                $count++;
            } else {
                if (! $on) {
                    $skipped[] = ['sku' => $sku, 'why' => 'Not on the shelf.'];

                    continue;
                }
                if (! empty($shelf['skus'][$sku]['protected'])) {
                    $skipped[] = ['sku' => $sku, 'why' => 'Protected: ' . $shelf['skus'][$sku]['protected'] . '.'];

                    continue;
                }
                $o = $this->option([], [$mv], $shelfNow, $base, $weights, $factors, $marginShelf, $transfer);
                $shelfNow = array_values(array_diff($shelfNow, [$sku]));
                $count--;
            }
            $o['step'] = count($rows) + 1;
            $o['forced'] = false;
            $o['user'] = true;
            $rows[] = $this->change($o, $base);
        }

        $recovers = array_values(array_filter($recovers, fn ($r) => in_array((string) $r['sku'], $shelfNow, true)));
        $changes = array_merge($rows, $this->recoverRows($recovers, $shelfNow, $marginShelf, $transfer), $this->protectedRows($shelf));
        $impact = $this->impact($changes, $base, $count);

        $floor = $strategy['sales_floor'] ?? null;
        $violations = [];
        if ($count > $maxN) {
            $violations[] = ['key' => 'max_size', 'why' => "The range would hold {$count} products; its limit is {$maxN}."];
        }
        if ($count < $minN) {
            $violations[] = ['key' => 'min_size', 'why' => "The range would hold {$count} products; it may not go below {$minN}."];
        }
        if ($floor !== null && (float) $base['sales'] > 0 && $impact['sales_pct'][1] < (float) $floor - self::EPS) {
            $violations[] = ['key' => 'sales_floor', 'why' => 'Category sales would fall below the floor set for this category ('
                . number_format((float) $floor * 100, 0) . '%).'];
        }

        $acting = array_filter($changes, fn ($c) => $c['kind'] !== AssortmentPlan::PROTECT);
        $tiers = array_column($acting, 'tier');
        $rank = ['established' => 3, 'likely' => 2, 'speculative' => 1];
        usort($tiers, fn ($x, $y) => ($rank[$x] ?? 1) <=> ($rank[$y] ?? 1));

        return [
            'feasible'        => true,
            'reason'          => null,
            'current_count'   => $n0,
            'proposed_count'  => $count,
            'changes'         => array_values($changes),
            'left_out'        => [],
            'impact'          => $impact,
            'constraints'     => [
                'current_count' => $n0, 'max_size' => $maxN, 'min_size' => $minN, 'room' => (float) $strategy['room'],
                'set_max' => $strategy['max_size'], 'set_min' => $strategy['min_size'] ?? null, 'sales_floor' => $floor,
                'objective' => $strategy['objective'], 'role' => $strategy['role'], 'bound' => array_column($violations, 'key'),
            ],
            'violations'      => $violations,
            'skipped'         => $skipped,
            'shelf'           => $shelfNow,
            'confidence'      => $acting === [] ? 0.0 : min(array_column($acting, 'confidence')),
            'confidence_tier' => $tiers[0] ?? 'speculative',
            'value_mid'       => $impact['sales'][1],
            'margin_known'    => $knownMargin !== null,
            'version'         => self::VERSION,
        ];
    }

    /**
     * The figures a scenario is compared on (Studio → Compare): size, sales,
     * margin, availability, stock, space, sales lost and sales gained by the
     * changes, and confidence.
     */
    public function summary(array $result): array
    {
        $i = $result['impact'];
        $lost = $gained = [0.0, 0.0, 0.0];
        $changes = 0;
        foreach ($result['changes'] as $c) {
            if (in_array($c['kind'], [AssortmentPlan::PROTECT], true) || ! ($c['ticked'] ?? true)) {
                continue;
            }
            $changes += $c['kind'] === AssortmentPlan::RECOVER ? 0 : 1;
            $s = $c['sales'];
            if ($s[1] < 0) {
                $lost = $this->plus($lost, $s);
            } else {
                $gained = $this->plus($gained, $s);
            }
        }

        return [
            'current_count' => (int) $result['current_count'],
            'count'         => (int) $result['proposed_count'],
            'changes'       => $changes,
            'recovers'      => count(array_filter($result['changes'], fn ($c) => $c['kind'] === AssortmentPlan::RECOVER)),
            'sales'         => $i['sales'], 'sales_pct' => $i['sales_pct'],
            'margin'        => $i['margin'], 'margin_pct' => $i['margin_pct'],
            'availability'  => $i['availability'],
            'stock'         => $i['stock'], 'stock_pct' => $i['stock_pct'],
            'space_pct'     => $i['space_margin_pct'] ?? null,
            'lost'          => array_map(fn ($v) => round($v, 2), $lost),
            'gained'        => array_map(fn ($v) => round($v, 2), $gained),
            'confidence'    => round((float) $result['confidence'], 4),
            'confidence_tier' => $result['confidence_tier'],
            'feasible'      => (bool) $result['feasible'],
            'reason'        => $result['reason'] ?? null,
            'violations'    => array_column($result['violations'] ?? [], 'why'),
            'margin_known'  => (bool) ($result['margin_known'] ?? false),
        ];
    }

    /** @return array{0:int,1:int} most and fewest products the shelf may hold */
    public function limits(int $n0, array $strategy): array
    {
        $maxN = $strategy['max_size'] ?? ($n0 + (int) ceil($n0 * (float) $strategy['room']));
        $minN = $strategy['min_size'] ?? max(1, $n0 - (int) ceil(0.2 * $n0));

        return [(int) $maxN, (int) max(1, min($minN, $maxN))];
    }

    private function recoverRows(array $recovers, array $shelfNow, float $marginShelf, callable $transfer): array
    {
        $rows = [];
        foreach ($recovers as $r) {
            $t = $this->share($transfer($r['sku'], array_values(array_diff($shelfNow, [$r['sku']]))));
            $m = $r['margin_rate'] ?? $marginShelf;
            $sales = $this->range($r['gross_sales'], 1 - $t[2], 1 - $t[1], 1 - $t[0]);
            $rows[] = [
                'kind' => AssortmentPlan::RECOVER, 'key' => 'recover:' . $r['sku'], 'gap_id' => $r['gap_id'], 'sku' => $r['sku'], 'name' => $r['name'],
                'sales' => $sales, 'margin' => array_map(fn ($v) => round($v * $m, 2), $sales), 'stock' => 0.0, 'availability' => round((float) $r['gross_sales'], 2),
                'tier' => $r['tier'], 'confidence' => (float) $r['confidence'], 'ticked' => true, 'source' => 'engine',
                'why' => 'Keeps running out: fixing the stock recovers the sales its buyers do not take elsewhere. Always part of the plan — never a delist.',
            ];
        }

        return $rows;
    }

    private function protectedRows(array $shelf): array
    {
        $rows = [];
        foreach ($shelf['skus'] as $sku => $p) {
            if (! empty($p['protected'])) {
                $rows[] = ['kind' => AssortmentPlan::PROTECT, 'key' => 'protect:' . $sku, 'sku' => (string) $sku,
                    'name' => (string) ($p['name'] ?? $sku), 'why' => (string) $p['protected'], 'ticked' => false];
            }
        }

        return $rows;
    }

    // ── Scoring one move ──────────────────────────────────────────────────────

    /** Figures and objective score of a move (adds then delists) on the shelf as it stands. */
    private function option(array $adds, array $delists, array $shelfNow, array $base, array $weights, array $factors, float $marginShelf, callable $transfer): array
    {
        $shelf = $shelfNow;
        $sales = [0.0, 0.0, 0.0];
        $margin = [0.0, 0.0, 0.0];
        $stock = 0.0;
        $parts = [];
        $factor = 1.0;

        foreach ($adds as $a) {
            $t = $this->share($transfer($a['sku'], $shelf));
            $m = $a['margin_rate'] ?? $marginShelf;
            $gross = (float) $a['gross_sales'];
            $s = $this->range($gross, 1 - $t[2], 1 - $t[1], 1 - $t[0]);
            $g = [$gross * ($m - $t[2] * $marginShelf), $gross * ($m - $t[1] * $marginShelf), $gross * ($m - $t[0] * $marginShelf)];
            $k = (float) ($a['units_per_day'] ?? 0) * self::STOCK_COVER_DAYS * (float) ($a['cost'] ?? 0);
            $sales = $this->plus($sales, $s);
            $margin = $this->plus($margin, $g);
            $stock += $k;
            $parts[] = ['kind' => 'add', 'item' => $a, 'taken' => $t, 'sales' => $s, 'margin' => $g, 'stock' => $k];
            $factor *= $factors['add'];
            $shelf[] = $a['sku'];
        }
        foreach ($delists as $d) {
            $rest = array_values(array_diff($shelf, [$d['sku']]));
            $t = $this->share($transfer($d['sku'], $rest));
            $m = $d['margin_rate'] ?? $marginShelf;
            $cur = (float) $d['current_sales'];
            // Lose what does not move; the moved part earns the shelf's margin instead of its own.
            $s = [-$cur * (1 - $t[0]), -$cur * (1 - $t[1]), -$cur * (1 - $t[2])];
            $g = [-$cur * ($m - $t[0] * $marginShelf), -$cur * ($m - $t[1] * $marginShelf), -$cur * ($m - $t[2] * $marginShelf)];
            $k = -(float) ($d['stock_value'] ?? 0);
            $sales = $this->plus($sales, $s);
            $margin = $this->plus($margin, $g);
            $stock += $k;
            $parts[] = ['kind' => 'delist', 'item' => $d, 'moved' => $t, 'sales' => $s, 'margin' => $g, 'stock' => $k];
            $factor *= $factors['delist'];
            $shelf = $rest;
        }

        $dn = count($adds) - count($delists);
        $impacts = $this->impacts($sales[1], $margin[1], $stock, 0.0, $dn, $base);
        $score = $this->scorer->score($weights, array_intersect_key($impacts, $weights) + array_fill_keys(array_keys($weights), 0.0))->blended;
        // The role leans on a move's size, never flips its direction.
        $score = $score > 0 ? $score * $factor : $score / max(0.01, $factor);

        return [
            'key'     => implode('+', array_merge(array_map(fn ($a) => 'a:' . $a['sku'], $adds), array_map(fn ($d) => 'd:' . $d['sku'], $delists))),
            'adds'    => $adds,
            'delists' => $delists,
            'parts'   => $parts,
            'sales'   => $sales,
            'margin'  => $margin,
            'stock'   => $stock,
            'impacts' => $impacts,
            'score'   => round($score, 6),
        ];
    }

    /**
     * Each measure as a % change of the category at the store (the stock
     * figure is a saving, so less stock is positive; space is margin per
     * product on the shelf).
     */
    private function impacts(float $sales, float $margin, float $stock, float $availability, int $dn, array $base): array
    {
        $bs = max(1.0, (float) $base['sales']);
        $bm = (float) $base['margin'] > 0 ? (float) $base['margin'] : $bs;
        $bk = (float) $base['stock'];   // 0 = no stock-on-hand file: stock does not count, rather than every add looking ruinous
        $n = max(1, (int) $base['count']);
        $perSlot = $bm / $n;
        $perSlotAfter = ($bm + $margin) / max(1, $n + $dn);

        return [
            'sales'        => $sales / $bs,
            'margin'       => $margin / $bm,
            'stock'        => $bk > 0 ? -$stock / $bk : 0.0,
            'availability' => $availability / $bs,
            'space'        => ($perSlotAfter - $perSlot) / $perSlot,
        ];
    }

    // ── Output shapes ─────────────────────────────────────────────────────────

    private function change(array $m, array $base): array
    {
        $isSwap = count($m['adds']) === 1 && count($m['delists']) === 1;
        $pct = fn (float $v) => ($v >= 0 ? '+' : '−') . number_format(abs($v) * 100, 1) . '%';
        $objective = $m['impacts'];
        $bits = [];
        foreach ($m['parts'] as $p) {
            $name = $p['item']['name'];
            if ($p['kind'] === 'add') {
                $bits[] = $p['taken'][1] > 0
                    ? "{$name}: about " . (int) round($p['taken'][1] * 100) . '% of its sales come from the shelf as it stands'
                    : "{$name}: nothing similar on the shelf, its sales are new";
            } else {
                $bits[] = "{$name}: about " . (int) round($p['moved'][1] * 100) . '% of its buyers move to the rest of the shelf';
            }
        }
        $lead = match (true) {
            $m['user'] ?? false => 'Change ' . $m['step'] . ($m['score'] > self::EPS ? ' (helps the objective): ' : ' (works against the objective): '),
            $m['forced']        => 'Needed to bring the shelf within its limit. ',
            default             => 'Step ' . $m['step'] . ': the best move left. ',
        };
        $why = $lead
            . implode('; ', $bits) . '. Category sales ' . $pct($objective['sales']) . ', margin ' . $pct($objective['margin']) . '.'
            . (isset($m['swapped_for']) ? ' Chosen over ' . $m['swapped_for'] . ', which added less.' : '');

        $row = [
            'kind'       => $isSwap ? AssortmentPlan::SWAP : ($m['adds'] !== [] ? AssortmentPlan::ADD : AssortmentPlan::DELIST),
            'step'       => $m['step'],
            'sales'      => array_map(fn ($v) => round($v, 2), $m['sales']),
            'margin'     => array_map(fn ($v) => round($v, 2), $m['margin']),
            'stock'      => round($m['stock'], 2),
            'availability' => 0.0,
            'score'      => $m['score'],
            'why'        => $why,
            'ticked'     => true,
        ];
        $items = array_merge($m['adds'], $m['delists']);
        // A change the engine did not propose (picked in the Studio from the shelf or from what similar stores carry).
        $row['source'] = collect($items)->contains(fn ($i) => ($i['source'] ?? 'engine') === 'user') ? 'user' : 'engine';
        $row['tier'] = collect($items)->sortBy(fn ($i) => ['established' => 3, 'likely' => 2][$i['tier']] ?? 1)->first()['tier'];
        $row['confidence'] = min(array_map(fn ($i) => (float) $i['confidence'], $items));
        if ($isSwap) {
            $a = $m['adds'][0];
            $d = $m['delists'][0];
            $row += ['key' => 'swap:' . $d['sku'] . '>' . $a['sku'], 'sku' => $a['sku'], 'name' => $a['name'], 'gap_id' => $a['gap_id'],
                'out_sku' => $d['sku'], 'out_name' => $d['name'], 'out_gap_id' => $d['gap_id']];
        } else {
            $i = $items[0];
            $row += ['key' => $row['kind'] . ':' . $i['sku'], 'sku' => $i['sku'], 'name' => $i['name'], 'gap_id' => $i['gap_id']];
        }

        return $row;
    }

    private function leftOut(array $c, string $reason, array $o): array
    {
        return [
            'kind'   => $c['kind'], 'sku' => $c['sku'], 'name' => $c['name'], 'gap_id' => $c['gap_id'], 'reason' => $reason,
            'sales'  => array_map(fn ($v) => round($v, 2), $o['sales']),
            'why'    => match ($reason) {
                'shelf_full'  => 'The shelf is at its limit and no delist makes room for it.',
                'min_size'    => 'The range would fall below its minimum.',
                'sales_floor' => 'It would take category sales below the floor set for this category.',
                default       => $c['kind'] === AssortmentPlan::ADD
                    ? 'Once the other changes are in, it adds too little that is new to the store.'
                    : 'Once the other changes are in, too many of its buyers would be lost.',
            },
        ];
    }

    /** Totals of the plan's ticked changes, as ranges and as % of the category at the store. */
    public function impact(array $changes, array $base, int $count): array
    {
        $sales = $margin = [0.0, 0.0, 0.0];
        $stock = $avail = 0.0;
        foreach ($changes as $c) {
            if (($c['kind'] ?? '') === AssortmentPlan::PROTECT || ! ($c['ticked'] ?? true)) {
                continue;
            }
            $sales = $this->plus($sales, $c['sales']);
            $margin = $this->plus($margin, $c['margin']);
            $stock += (float) $c['stock'];
            $avail += (float) ($c['availability'] ?? 0);
        }
        $bs = max(1.0, (float) $base['sales']);
        $bm = (float) $base['margin'] > 0 ? (float) $base['margin'] : null;
        $bk = (float) $base['stock'] > 0 ? (float) $base['stock'] : null;
        $n0 = max(1, (int) $base['count']);

        return [
            'baseline'     => ['sales' => round((float) $base['sales'], 2), 'margin' => round((float) $base['margin'], 2), 'stock' => round((float) $base['stock'], 2), 'count' => (int) $base['count']],
            'sales'        => array_map(fn ($v) => round($v, 2), $sales),
            'sales_pct'    => array_map(fn ($v) => round($v / $bs, 4), $sales),
            'margin'       => array_map(fn ($v) => round($v, 2), $margin),
            'margin_pct'   => $bm ? array_map(fn ($v) => round($v / $bm, 4), $margin) : null,
            'stock'        => round($stock, 2),
            'stock_pct'    => $bk ? round($stock / $bk, 4) : null,
            'availability' => round($avail, 2),
            'availability_pct' => round($avail / $bs, 4),
            'count'        => $count,
            'space_pct'    => round(($count - $n0) / $n0, 4),
            // Space productivity until shelf metres are loaded: margin (or sales) per product on the shelf.
            'space_margin_pct' => round(((($bm ?? $bs) + $margin[1]) / max(1, $count)) / (($bm ?? $bs) / $n0) - 1, 4),
            'basis'        => 'estimated',
        ];
    }

    private function infeasible(array $shelf, array $recovers, array $protected, array $constraints, string $reason): array
    {
        $constraints['bound'][] = 'infeasible';

        return [
            'feasible' => false, 'reason' => $reason, 'current_count' => (int) $shelf['baseline']['count'],
            'proposed_count' => (int) $shelf['baseline']['count'], 'changes' => [], 'left_out' => [],
            'impact' => $this->impact([], $shelf['baseline'], (int) $shelf['baseline']['count']),
            'constraints' => $constraints, 'confidence' => 0.0, 'confidence_tier' => 'speculative', 'value_mid' => 0.0,
            'version' => self::VERSION,
        ];
    }

    // ── Small helpers ─────────────────────────────────────────────────────────

    /** low / mid / high share; an unknown share is 0–100% (nothing invented). */
    private function share(array $t): array
    {
        return ($t['mid'] ?? null) === null ? [0.0, 0.5, 1.0] : [(float) $t['low'], (float) $t['mid'], (float) $t['high']];
    }

    private function range(float $v, float $lo, float $mid, float $hi): array
    {
        return [$v * $lo, $v * $mid, $v * $hi];
    }

    private function plus(array $a, array $b): array
    {
        return [$a[0] + $b[0], $a[1] + $b[1], $a[2] + $b[2]];
    }

    private function withBack(array $shelf, array $delists): array
    {
        return array_values(array_unique(array_merge($shelf, array_column($delists, 'sku'))));
    }

    /** The shelf's margin rate, weighted by sales (what moved sales earn); null when no cost prices are known. */
    private function shelfMargin(array $skus): ?float
    {
        $s = $m = 0.0;
        foreach ($skus as $p) {
            if (($p['margin_rate'] ?? null) !== null && ($p['sales'] ?? 0) > 0) {
                $s += $p['sales'];
                $m += $p['sales'] * $p['margin_rate'];
            }
        }

        return $s > 0 ? $m / $s : null;
    }
}
