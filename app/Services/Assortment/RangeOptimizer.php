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
 * Phase 4 — shelf space (ShelfSpace): with shelf metres the limit is metres,
 * not a count. An add fits the free metres, or once facings of over-spaced
 * products (more of the shelf than of sales) are cut — one at a time, never
 * below their fewest, only when facings are known — or with a delist beside
 * it. A shelf already over its metres is brought within them first. "Space
 * productivity" is then margin per metre. Facing cuts are their own rows and
 * are assumed to cost no sales the product needs (said on each row).
 *
 * Every move keeps its reason; every candidate left out keeps why.
 * Figures are per year: sales and margin as low / mid / high, stock at cost.
 */
final class RangeOptimizer
{
    public const VERSION = 'opt-2026.10.2';   // .2: shelf metres and facings (Phase 4)

    public const STOCK_COVER_DAYS = 21;   // stock an added product needs on the shelf, in days of its sales

    private const EPS = 1e-6;

    private const EPS_M = 1e-4;   // metres

    /** @var array<string,string> sku => name of the shelf being worked on (for reasons that name products) */
    private array $names = [];

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

        usort($candidates, fn ($a, $b) => [$a['kind'], $a['sku']] <=> [$b['kind'], $b['sku']]);
        $adds = array_values(array_filter($candidates, fn ($c) => $c['kind'] === AssortmentPlan::ADD));
        $delists = array_values(array_filter($candidates, fn ($c) => $c['kind'] === AssortmentPlan::DELIST
            && empty($shelf['skus'][$c['sku']]['protected'])));
        $recovers = array_values(array_filter($candidates, fn ($c) => $c['kind'] === AssortmentPlan::RECOVER));

        // Phase 4: with shelf metres the shelf's limit is metres, not a count (a set "most products" still applies).
        $sp = $this->spaceState($shelf, $recovers);
        [$maxN, $minN] = $this->limits($n0, $strategy, $sp !== null);
        if ($sp !== null) {
            $base['used'] = $sp['used'];
        }
        $weights = CategoryStrategy::OBJECTIVES[$strategy['objective']]['weights'] ?? CategoryStrategy::OBJECTIVES['balanced']['weights'];
        $factors = CategoryStrategy::ROLE_FACTORS[$strategy['role']] ?? ['add' => 1.0, 'delist' => 1.0];
        $floor = $strategy['sales_floor'];

        $current = array_keys($shelf['skus']);
        $protected = array_filter(array_map(fn ($p) => $p['protected'] ?? null, $shelf['skus']));
        $knownMargin = $this->shelfMargin($shelf['skus']);
        // Without cost prices margin is not invented: "margin" then counts sales (and the plan says so).
        $marginShelf = $knownMargin ?? 1.0;
        $this->names = array_map(fn ($p) => (string) ($p['name'] ?? ''), $shelf['skus']);

        $constraints = [
            'current_count' => $n0, 'max_size' => $sp !== null && $strategy['max_size'] === null ? null : $maxN, 'min_size' => $minN,
            'room' => (float) $strategy['room'], 'set_max' => $strategy['max_size'], 'sales_floor' => $floor, 'objective' => $strategy['objective'],
            'role' => $strategy['role'], 'bound' => [], 'space' => $this->spaceInfo($shelf, $sp),
        ];

        // ── Feasibility ───────────────────────────────────────────────────────
        $mustRemove = max(0, $n0 - $maxN);
        $mustSkus = array_values(array_unique(array_merge(array_map('strval', array_keys($protected)), array_map('strval', array_column($recovers, 'sku')))));
        $mustStay = count($mustSkus);
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
        if ($sp !== null) {
            $minSpace = 0.0;
            foreach ($mustSkus as $s) {
                if (isset($sp['w'][$s])) {
                    $minSpace += $sp['w'][$s] * ($sp['level'] === ShelfSpace::BASIS_FACINGS ? $sp['min'][$s] : $sp['f'][$s]);
                }
            }
            if ($minSpace > $sp['metres'] + self::EPS_M) {
                return $this->infeasible($shelf, $recovers, $protected, $constraints, sprintf(
                    'No feasible range within the shelf: the products that must stay need at least %.2f m and the shelf has %.2f m.',
                    $minSpace, $sp['metres']));
            }
            if ($sp['used'] > $sp['metres'] + self::EPS_M) {
                $free = $this->cutPotential($sp) + array_sum(array_map(fn ($d) => $sp['occ'][(string) $d['sku']] ?? 0.0, $delists));
                if ($sp['used'] - $free > $sp['metres'] + self::EPS_M) {
                    return $this->infeasible($shelf, $recovers, $protected, $constraints, sprintf(
                        'No feasible range within the shelf: it uses %.2f m of %.2f m, and cutting over-spaced facings and the %d delist(s) the evidence supports free only %.2f m.',
                        $sp['used'], $sp['metres'], count($delists), $free));
                }
            }
        }

        // ── Greedy moves ──────────────────────────────────────────────────────
        $state = ['shelf' => $current, 'sales' => 0.0, 'count' => $n0];
        $moves = [];
        $left = [];
        $chosenAdds = $chosenDelists = [];
        $cutStep = [];

        // Already over its metres: over-spaced facings are cut first (no sales it needs are lost — assumed, and said).
        if ($sp !== null && $sp['used'] > $sp['metres'] + self::EPS_M) {
            $cuts = $this->cutsFor($sp, $sp['used'] - $sp['metres']);
            if ($cuts !== null) {
                $sp = $this->applySpace($sp, [], [], $cuts);
                foreach ($cuts as $s => $n) {
                    $cutStep[$s] = 0;
                }
            }
        }

        while (true) {
            $options = [];
            $forced = $state['count'] > $maxN || ($sp !== null && $sp['used'] > $sp['metres'] + self::EPS_M);
            foreach ($adds as $a) {
                if (isset($chosenAdds[$a['sku']]) || $forced) {
                    continue;
                }
                if ($sp === null) {
                    if ($state['count'] + 1 <= $maxN) {
                        $options[] = $this->option([$a], [], $state['shelf'], $base, $weights, $factors, $marginShelf, $transfer);
                    } else {
                        foreach ($delists as $d) {
                            if (! isset($chosenDelists[$d['sku']]) && $state['count'] - 1 >= $minN - 1) {
                                $options[] = $this->option([$a], [$d], $state['shelf'], $base, $weights, $factors, $marginShelf, $transfer);
                            }
                        }
                    }

                    continue;
                }
                // Metres: it fits in the free space, or once over-spaced facings are cut; else it needs a delist beside it.
                $short = $sp['used'] + $this->need($sp, $a) - $sp['metres'];
                if ($state['count'] + 1 <= $maxN && ($cuts = $this->cutsFor($sp, $short)) !== null) {
                    $options[] = $this->option([$a], [], $state['shelf'], $base, $weights, $factors, $marginShelf, $transfer,
                        $this->spaceDelta($sp, [$a], [], $cuts));

                    continue;
                }
                foreach ($delists as $d) {
                    if (isset($chosenDelists[$d['sku']]) || $state['count'] - 1 < $minN - 1) {
                        continue;
                    }
                    $cuts = $this->cutsFor($sp, $short - ($sp['occ'][(string) $d['sku']] ?? 0.0), [(string) $d['sku'] => true]);
                    if ($cuts !== null) {
                        $options[] = $this->option([$a], [$d], $state['shelf'], $base, $weights, $factors, $marginShelf, $transfer,
                            $this->spaceDelta($sp, [$a], [$d], $cuts));
                    }
                }
            }
            foreach ($delists as $d) {
                if (! isset($chosenDelists[$d['sku']]) && $state['count'] - 1 >= $minN) {
                    $options[] = $this->option([], [$d], $state['shelf'], $base, $weights, $factors, $marginShelf, $transfer,
                        $sp !== null ? $this->spaceDelta($sp, [], [$d], []) : null);
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
            if ($sp !== null) {
                $sp = $this->applySpace($sp, $best['adds'], $best['delists'], $best['space']['cuts'] ?? []);
                foreach ($best['space']['cuts'] ?? [] as $s => $n) {
                    $cutStep[$s] ??= $best['step'];
                }
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
                // With metres, only one that fits where the chosen one stood (and the space left) may take its place.
                $delta = null;
                if ($sp !== null) {
                    $room = $this->need($sp, $m['adds'][0]) + ($sp['metres'] - $sp['used']);
                    if ($this->need($sp, $b) > $room + self::EPS_M) {
                        continue;
                    }
                    $delta = ['move' => $this->need($sp, $b) - array_sum(array_map(fn ($d) => $m['space']['occ'][(string) $d['sku']] ?? 0.0, $m['delists'])),
                        'cuts' => $m['space']['cuts'], 'cuts_freed' => $m['space']['cuts_freed'], 'occ' => $m['space']['occ'],
                        'facings' => [(string) $b['sku'] => $this->entryFacings($sp, $b)]];
                }
                $alt = $this->option([$b], $m['delists'], $this->withBack($without, $m['delists']), $base, $weights, $factors, $marginShelf, $transfer, $delta);
                $cur = $this->option($m['adds'], $m['delists'], $this->withBack($without, $m['delists']), $base, $weights, $factors, $marginShelf, $transfer, $m['space'] ?? null);
                if ($alt['score'] > $cur['score'] + self::EPS) {
                    unset($chosenAdds[$m['adds'][0]['sku']]);
                    $chosenAdds[$b['sku']] = true;
                    $state['shelf'] = array_merge($without, [$b['sku']]);
                    if ($sp !== null) {
                        $sp = $this->swapSpace($sp, $m['adds'][0], $b);
                    }
                    $alt['step'] = $m['step'];
                    $alt['forced'] = false;
                    $alt['swapped_for'] = $m['adds'][0]['sku'];
                    $moves[$i] = $alt;
                    $m = $alt;
                    $without = array_values(array_diff($state['shelf'], [$b['sku']]));
                }
            }
        }

        // ── The chosen moves, re-measured in order on the final choice ────────
        $shelfNow = $current;
        foreach ($moves as $i => $m) {
            $o = $this->option($m['adds'], $m['delists'], $shelfNow, $base, $weights, $factors, $marginShelf, $transfer, $m['space'] ?? null);
            $moves[$i] = array_merge($o, array_intersect_key($m, array_flip(['step', 'forced', 'swapped_for'])));
            $shelfNow = array_values(array_diff(array_merge($shelfNow, array_column($m['adds'], 'sku')), array_column($m['delists'], 'sku')));
        }

        // ── Why each candidate left out was left out ──────────────────────────
        $finalCount = $n0 + count($chosenAdds) - count($chosenDelists);
        foreach ($adds as $a) {
            if (! isset($chosenAdds[$a['sku']])) {
                $fits = true;
                $delta = null;
                if ($sp !== null) {
                    $cuts = $this->cutsFor($sp, $sp['used'] + $this->need($sp, $a) - $sp['metres']);
                    $fits = $cuts !== null;
                    $delta = $this->spaceDelta($sp, [$a], [], $cuts ?? []);
                }
                $o = $this->option([$a], [], $state['shelf'], $base, $weights, $factors, $marginShelf, $transfer, $delta);
                $why = $o['score'] <= self::EPS ? 'no_value' : ($finalCount >= $maxN || ! $fits ? 'shelf_full' : 'sales_floor');
                $left[] = $this->leftOut($a, $why, $o, $sp !== null ? ['need' => $this->need($sp, $a), 'free' => $sp['metres'] - $sp['used']] : null);
                if ($why === 'shelf_full') {
                    $constraints['bound'][] = $sp !== null ? 'metres' : 'max_size';
                }
            }
        }
        foreach ($delists as $d) {
            if (! isset($chosenDelists[$d['sku']])) {
                $o = $this->option([], [$d], $state['shelf'], $base, $weights, $factors, $marginShelf, $transfer,
                    $sp !== null && isset($sp['occ'][(string) $d['sku']]) ? $this->spaceDelta($sp, [], [$d], []) : null);
                $why = $o['score'] <= self::EPS ? 'no_value' : ($finalCount <= $minN ? 'min_size' : 'sales_floor');
                $left[] = $this->leftOut($d, $why, $o);
                if ($why === 'min_size') {
                    $constraints['bound'][] = 'min_size';
                }
            }
        }

        // ── Recoveries: always in; a stock fix, not a range change ────────────
        $recoverRows = $this->recoverRows($recovers, $state['shelf'], $marginShelf, $transfer);

        $moveRows = array_map(fn ($m) => $this->change($m, $base), $moves);
        if ($sp !== null) {
            // A product cut to make room and later delisted: its delist frees all its space (no separate facings row).
            foreach ($sp['cut'] as $sku => $n) {
                if (isset($sp['gone'][$sku])) {
                    foreach ($moveRows as $i => $r) {
                        if (($r['kind'] === AssortmentPlan::DELIST && $r['sku'] === $sku) || ($r['out_sku'] ?? null) === $sku) {
                            $m = round((float) ($r['metres'] ?? 0) - $n * $sp['w'][$sku], 4);
                            $moveRows[$i]['metres'] = $m;
                            $moveRows[$i]['why'] = preg_replace('/(Takes|Frees) [0-9.]+ m of shelf/',
                                ($m >= 0 ? 'Takes ' : 'Frees ') . number_format(abs($m), 2) . ' m of shelf', $r['why']);
                        }
                    }
                }
            }
        }
        $changes = array_merge($moveRows, $this->cutRows($shelf, $sp, $cutStep), $recoverRows, $this->protectedRows($shelf));
        $constraints['space'] = $this->spaceInfo($shelf, $sp);
        $impact = $this->impact($changes, $base, $finalCount, $constraints['space']);

        $acting = array_filter($changes, fn ($c) => $c['kind'] !== AssortmentPlan::PROTECT && ($c['confidence'] ?? null) !== null);
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
    public function evaluate(array $shelf, array $moves, array $recovers, array $strategy, callable $transfer, array $facings = []): array
    {
        $base = $shelf['baseline'];
        $n0 = (int) $base['count'];
        $sp = $this->spaceState($shelf, $recovers);
        [$maxN, $minN] = $this->limits($n0, $strategy, $sp !== null);
        if ($sp !== null) {
            $base['used'] = $sp['used'];
        }
        $weights = CategoryStrategy::OBJECTIVES[$strategy['objective']]['weights'] ?? CategoryStrategy::OBJECTIVES['balanced']['weights'];
        $factors = CategoryStrategy::ROLE_FACTORS[$strategy['role']] ?? ['add' => 1.0, 'delist' => 1.0];
        $knownMargin = $this->shelfMargin($shelf['skus']);
        $marginShelf = $knownMargin ?? 1.0;
        $this->names = array_map(fn ($p) => (string) ($p['name'] ?? ''), $shelf['skus']);

        $shelfNow = array_map('strval', array_keys($shelf['skus']));
        $count = $n0;
        $rows = $skipped = [];

        // Facings the person set (only when facings are known): space moves, no sales change is assumed.
        if ($sp !== null && $sp['level'] === ShelfSpace::BASIS_FACINGS) {
            foreach ($facings as $sku => $n) {
                $sku = (string) $sku;
                if (! isset($sp['f'][$sku])) {
                    continue;
                }
                $from = (int) round($sp['f'][$sku]);
                $to = max(1, (int) $n);
                if ($to === $from) {
                    continue;
                }
                $delta = ($to - $from) * $sp['w'][$sku];
                $sp['f'][$sku] = $to;
                $sp['occ'][$sku] += $delta;
                $sp['used'] += $delta;
                $rows[] = [
                    'kind' => AssortmentPlan::FACINGS, 'key' => 'facings:' . $sku, 'step' => count($rows) + 1, 'sku' => $sku,
                    'name' => $this->names[$sku] ?? $sku, 'gap_id' => null, 'from' => $from, 'to' => $to, 'metres' => round($delta, 4),
                    'sales' => [0.0, 0.0, 0.0], 'margin' => [0.0, 0.0, 0.0], 'stock' => 0.0, 'availability' => 0.0,
                    'tier' => null, 'confidence' => null, 'ticked' => true, 'source' => 'user',
                    'why' => sprintf('Change %d: %d → %d facings (%s%.2f m of shelf). No change in sales is assumed from facings alone.',
                        count($rows) + 1, $from, $to, $delta >= 0 ? '+' : '−', abs($delta)),
                ];
            }
        }

        foreach ($moves as $mv) {
            $sku = (string) $mv['sku'];
            $on = in_array($sku, $shelfNow, true);
            if ($mv['kind'] === AssortmentPlan::ADD) {
                if ($on) {
                    $skipped[] = ['sku' => $sku, 'why' => 'Already on the shelf.'];

                    continue;
                }
                $delta = $sp !== null ? $this->spaceDelta($sp, [$mv], [], []) : null;
                $o = $this->option([$mv], [], $shelfNow, $base, $weights, $factors, $marginShelf, $transfer, $delta);
                $shelfNow[] = $sku;
                $count++;
                if ($sp !== null) {
                    $sp = $this->applySpace($sp, [$mv], [], []);
                }
            } else {
                if (! $on) {
                    $skipped[] = ['sku' => $sku, 'why' => 'Not on the shelf.'];

                    continue;
                }
                if (! empty($shelf['skus'][$sku]['protected'])) {
                    $skipped[] = ['sku' => $sku, 'why' => 'Protected: ' . $shelf['skus'][$sku]['protected'] . '.'];

                    continue;
                }
                $delta = $sp !== null ? $this->spaceDelta($sp, [], [$mv], []) : null;
                $o = $this->option([], [$mv], $shelfNow, $base, $weights, $factors, $marginShelf, $transfer, $delta);
                $shelfNow = array_values(array_diff($shelfNow, [$sku]));
                $count--;
                if ($sp !== null) {
                    $sp = $this->applySpace($sp, [], [$mv], []);
                }
            }
            $o['step'] = count($rows) + 1;
            $o['forced'] = false;
            $o['user'] = true;
            $rows[] = $this->change($o, $base);
        }

        $recovers = array_values(array_filter($recovers, fn ($r) => in_array((string) $r['sku'], $shelfNow, true)));
        $space = $this->spaceInfo($shelf, $sp);
        $changes = array_merge($rows, $this->recoverRows($recovers, $shelfNow, $marginShelf, $transfer), $this->protectedRows($shelf));
        $impact = $this->impact($changes, $base, $count, $space);

        $floor = $strategy['sales_floor'] ?? null;
        $violations = [];
        if ($count > $maxN) {
            $violations[] = ['key' => 'max_size', 'why' => "The range would hold {$count} products; its limit is {$maxN}."];
        }
        if ($count < $minN) {
            $violations[] = ['key' => 'min_size', 'why' => "The range would hold {$count} products; it may not go below {$minN}."];
        }
        $roomCuts = null;
        if ($sp !== null && $sp['used'] > $sp['metres'] + self::EPS_M) {
            $violations[] = ['key' => 'metres', 'why' => sprintf('The range would use %.2f m of the %.2f m shelf.', $sp['used'], $sp['metres'])];
            $roomCuts = $this->cutsFor($sp, $sp['used'] - $sp['metres']);
        }
        if ($floor !== null && (float) $base['sales'] > 0 && $impact['sales_pct'][1] < (float) $floor - self::EPS) {
            $violations[] = ['key' => 'sales_floor', 'why' => 'Category sales would fall below the floor set for this category ('
                . number_format((float) $floor * 100, 0) . '%).'];
        }

        $acting = array_filter($changes, fn ($c) => $c['kind'] !== AssortmentPlan::PROTECT && ($c['confidence'] ?? null) !== null);
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
                'current_count' => $n0, 'max_size' => $sp !== null && $strategy['max_size'] === null ? null : $maxN, 'min_size' => $minN,
                'room' => (float) $strategy['room'], 'set_max' => $strategy['max_size'], 'set_min' => $strategy['min_size'] ?? null, 'sales_floor' => $floor,
                'objective' => $strategy['objective'], 'role' => $strategy['role'], 'bound' => array_column($violations, 'key'), 'space' => $space,
            ],
            'violations'      => $violations,
            'room_cuts'       => $roomCuts,
            'facings_now'     => $sp !== null ? array_map(fn ($f) => (int) round($f), $sp['f']) : [],
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
            $changes += in_array($c['kind'], [AssortmentPlan::RECOVER, AssortmentPlan::FACINGS], true) ? 0 : 1;
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
            'facings'       => count(array_filter($result['changes'], fn ($c) => $c['kind'] === AssortmentPlan::FACINGS)),
            'space_basis'   => $result['constraints']['space']['basis'] ?? ShelfSpace::BASIS_COUNT,
            'metres'        => $i['space']['metres'] ?? null,
            'used_before'   => $i['space']['used_before'] ?? null,
            'used_after'    => $i['space']['used_after'] ?? null,
        ];
    }

    /** @return array{0:int,1:int} most and fewest products the shelf may hold */
    public function limits(int $n0, array $strategy, bool $metres = false): array
    {
        // With shelf metres the metres are the limit; "room to grow" stands in for them only when they are not known.
        $maxN = $strategy['max_size'] ?? ($metres ? PHP_INT_MAX : $n0 + (int) ceil($n0 * (float) $strategy['room']));
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
    private function option(array $adds, array $delists, array $shelfNow, array $base, array $weights, array $factors, float $marginShelf, callable $transfer, ?array $space = null): array
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
        $impacts = $this->impacts($sales[1], $margin[1], $stock, 0.0, $dn, $base, $space === null ? null : $space['move'] - $space['cuts_freed']);
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
            'space'   => $space,
        ];
    }

    /**
     * Each measure as a % change of the category at the store (the stock
     * figure is a saving, so less stock is positive; space is margin per
     * product on the shelf).
     */
    private function impacts(float $sales, float $margin, float $stock, float $availability, int $dn, array $base, ?float $dUsed = null): array
    {
        $bs = max(1.0, (float) $base['sales']);
        $bm = (float) $base['margin'] > 0 ? (float) $base['margin'] : $bs;
        $bk = (float) $base['stock'];   // 0 = no stock-on-hand file: stock does not count, rather than every add looking ruinous
        $n = max(1, (int) $base['count']);
        if ($dUsed !== null && (float) ($base['used'] ?? 0) > 0) {
            // Shelf metres known: space productivity is margin per metre.
            $perSlot = $bm / (float) $base['used'];
            $perSlotAfter = ($bm + $margin) / max(self::EPS_M, (float) $base['used'] + $dUsed);
        } else {
            $perSlot = $bm / $n;
            $perSlotAfter = ($bm + $margin) / max(1, $n + $dn);
        }

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
        $room = '';
        if (($m['space'] ?? null) !== null) {
            $sp = $m['space'];
            $room = ' ' . ($sp['move'] >= 0 ? 'Takes ' . number_format($sp['move'], 2) . ' m of shelf' : 'Frees ' . number_format(-$sp['move'], 2) . ' m of shelf')
                . ($sp['cuts'] ? '; room made by one facing fewer on ' . implode(', ', array_map(fn ($k) => $this->names[$k] ?? $k, array_keys($sp['cuts']))) : '') . '.';
        }
        $why = $lead
            . implode('; ', $bits) . '. Category sales ' . $pct($objective['sales']) . ', margin ' . $pct($objective['margin']) . '.'
            . $room
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
        if (($m['space'] ?? null) !== null) {
            $row['metres'] = round((float) $m['space']['move'], 4);
            if ($m['adds'] !== []) {
                $row['facings'] = $m['space']['facings'][(string) $m['adds'][0]['sku']] ?? null;
            }
        }
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

    private function leftOut(array $c, string $reason, array $o, ?array $space = null): array
    {
        return [
            'kind'   => $c['kind'], 'sku' => $c['sku'], 'name' => $c['name'], 'gap_id' => $c['gap_id'], 'reason' => $reason,
            'sales'  => array_map(fn ($v) => round($v, 2), $o['sales']),
            'why'    => match ($reason) {
                'shelf_full'  => $space !== null
                    ? sprintf('No room on the shelf: it needs %.2f m, %.2f m is free, and cutting over-spaced facings or a supported delist does not make enough room.',
                        $space['need'], max(0.0, $space['free']))
                    : 'The shelf is at its limit and no delist makes room for it.',
                'min_size'    => 'The range would fall below its minimum.',
                'sales_floor' => 'It would take category sales below the floor set for this category.',
                default       => $c['kind'] === AssortmentPlan::ADD
                    ? 'Once the other changes are in, it adds too little that is new to the store.'
                    : 'Once the other changes are in, too many of its buyers would be lost.',
            },
        ];
    }

    /** Totals of the plan's ticked changes, as ranges and as % of the category at the store. */
    public function impact(array $changes, array $base, int $count, ?array $space = null): array
    {
        $sales = $margin = [0.0, 0.0, 0.0];
        $stock = $avail = $metres = 0.0;
        foreach ($changes as $c) {
            if (($c['kind'] ?? '') === AssortmentPlan::PROTECT || ! ($c['ticked'] ?? true)) {
                continue;
            }
            $sales = $this->plus($sales, $c['sales']);
            $margin = $this->plus($margin, $c['margin']);
            $stock += (float) $c['stock'];
            $avail += (float) ($c['availability'] ?? 0);
            $metres += (float) ($c['metres'] ?? 0);
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
        ] + $this->metresImpact($space, $base, $sales[1], $margin[1], $metres);
    }

    /** With shelf metres: metres used before and after, and sales and margin per metre (space productivity). */
    private function metresImpact(?array $space, array $base, float $sales, float $margin, float $metres): array
    {
        if ($space === null || ($space['basis'] ?? ShelfSpace::BASIS_COUNT) === ShelfSpace::BASIS_COUNT || (float) ($space['used_before'] ?? 0) <= 0) {
            return [];
        }
        $before = (float) $space['used_before'];
        $after = max(self::EPS_M, $before + $metres);
        $bs = (float) $base['sales'];
        $bm = (float) $base['margin'] > 0 ? (float) $base['margin'] : $bs;

        return [
            'space_margin_pct' => $bm > 0 ? round((($bm + $margin) / $after) / ($bm / $before) - 1, 4) : null,
            'space' => [
                'basis'            => $space['basis'],
                'metres'           => (float) $space['metres'],
                'used_before'      => round($before, 2),
                'used_after'       => round($after, 2),
                'sales_per_metre'  => [round($bs / $before, 2), round(($bs + $sales) / $after, 2)],
                'margin_per_metre' => [round($bm / $before, 2), round(($bm + $margin) / $after, 2)],
            ],
        ];
    }

    private function infeasible(array $shelf, array $recovers, array $protected, array $constraints, string $reason): array
    {
        $constraints['bound'][] = 'infeasible';

        return [
            'feasible' => false, 'reason' => $reason, 'current_count' => (int) $shelf['baseline']['count'],
            'proposed_count' => (int) $shelf['baseline']['count'], 'changes' => [], 'left_out' => [],
            'impact' => $this->impact([], $shelf['baseline'], (int) $shelf['baseline']['count'], $constraints['space'] ?? null),
            'constraints' => $constraints, 'confidence' => 0.0, 'confidence_tier' => 'speculative', 'value_mid' => 0.0,
            'version' => self::VERSION,
        ];
    }

    // ── Shelf space (Phase 4) ─────────────────────────────────────────────────

    /** The shelf's space as the optimiser tracks it, or null when it counts products. */
    private function spaceState(array $shelf, array $recovers): ?array
    {
        $space = $shelf['space'] ?? null;
        if ($space === null || ($space['basis'] ?? ShelfSpace::BASIS_COUNT) === ShelfSpace::BASIS_COUNT) {
            return null;
        }
        $st = ['metres' => (float) $space['metres'], 'level' => $space['basis'], 'used' => 0.0, 'w' => [], 'f' => [], 'min' => [], 'occ' => [],
            'sales' => [], 'entry' => $space['entry'] ?? [], 'noCut' => array_fill_keys(array_map('strval', array_column($recovers, 'sku')), true),
            'cut' => [], 'gone' => []];
        foreach ($shelf['skus'] as $sku => $p) {
            $sku = (string) $sku;
            $w = (float) ($p['width'] ?? 0);
            $f = (float) ($p['facings'] ?? 1);
            $st['w'][$sku] = $w;
            $st['f'][$sku] = $f;
            $st['min'][$sku] = (float) ($p['min_facings'] ?? $f);
            $st['occ'][$sku] = $w * $f;
            $st['used'] += $w * $f;
            $st['sales'][$sku] = max(0.0, (float) ($p['sales'] ?? 0));
        }
        $st['used0'] = $st['used'];
        $st['sales_total'] = array_sum($st['sales']);

        return $st;
    }

    /** Metres an added product takes: its width × facings, else the shelf's average product. */
    private function need(array $sp, array $c): float
    {
        $e = $sp['entry'][(string) $c['sku']] ?? null;
        if ($e !== null) {
            return (float) $e['width'] * (float) $e['facings'];
        }

        return $sp['occ'] === [] ? 0.0 : array_sum($sp['occ']) / count($sp['occ']);
    }

    private function entryFacings(array $sp, array $c): float
    {
        return (float) ($sp['entry'][(string) $c['sku']]['facings'] ?? ($sp['f'] === [] ? 1 : array_sum($sp['f']) / count($sp['f'])));
    }

    /**
     * Facings cut from over-spaced products to free $short metres — the product
     * with the most shelf for its sales first, one facing at a time, never below
     * its fewest, never a product being restocked or just added. Only when
     * facings are known. null = not enough can be freed.
     *
     * @return array<string,int>|null sku => facings cut
     */
    private function cutsFor(array $sp, float $short, array $exclude = []): ?array
    {
        if ($short <= self::EPS_M) {
            return [];
        }
        if ($sp['level'] !== ShelfSpace::BASIS_FACINGS) {
            return null;
        }
        $f = $sp['f'];
        $occ = $sp['occ'];
        $used = $sp['used'];
        $total = max(self::EPS, $sp['sales_total']);
        $cuts = [];
        $freed = 0.0;
        while ($freed < $short - self::EPS_M) {
            $best = null;
            $bestRatio = 0.0;
            foreach ($f as $sku => $n) {
                if (isset($exclude[$sku]) || isset($sp['noCut'][$sku]) || $n - 1 < ($sp['min'][$sku] ?? 1) - self::EPS || $sp['w'][$sku] <= 0) {
                    continue;
                }
                $salesShare = ($sp['sales'][$sku] ?? 0.0) / $total;
                $ratio = $salesShare > 0 ? ($occ[$sku] / max(self::EPS_M, $used)) / $salesShare : INF;
                if ($ratio < ShelfSpace::OVER_SPACED) {
                    continue;
                }
                if ($best === null || $ratio > $bestRatio || ($ratio === $bestRatio && strcmp((string) $sku, (string) $best) < 0)) {
                    [$best, $bestRatio] = [(string) $sku, $ratio];
                }
            }
            if ($best === null) {
                return null;
            }
            $f[$best]--;
            $occ[$best] -= $sp['w'][$best];
            $used -= $sp['w'][$best];
            $freed += $sp['w'][$best];
            $cuts[$best] = ($cuts[$best] ?? 0) + 1;
        }

        return $cuts;
    }

    /** All the metres cutting over-spaced facings (as they stand) down to their fewest could free. */
    private function cutPotential(array $sp): float
    {
        if ($sp['level'] !== ShelfSpace::BASIS_FACINGS) {
            return 0.0;
        }
        $total = max(self::EPS, $sp['sales_total']);
        $free = 0.0;
        foreach ($sp['f'] as $sku => $n) {
            if (isset($sp['noCut'][$sku])) {
                continue;
            }
            $salesShare = ($sp['sales'][$sku] ?? 0.0) / $total;
            $ratio = $salesShare > 0 ? ($sp['occ'][$sku] / max(self::EPS_M, $sp['used'])) / $salesShare : INF;
            if ($ratio >= ShelfSpace::OVER_SPACED) {
                $free += max(0.0, $n - ($sp['min'][$sku] ?? $n)) * $sp['w'][$sku];
            }
        }

        return $free;
    }

    /** A move's space: metres its adds take less what its delists free, and the facings cut to make room. */
    private function spaceDelta(array $sp, array $adds, array $delists, array $cuts): array
    {
        $move = 0.0;
        $occ = $facings = [];
        foreach ($adds as $a) {
            $move += $this->need($sp, $a);
            $facings[(string) $a['sku']] = $this->entryFacings($sp, $a);
        }
        foreach ($delists as $d) {
            $occ[(string) $d['sku']] = $sp['occ'][(string) $d['sku']] ?? 0.0;
            $move -= $occ[(string) $d['sku']];
        }
        $freed = 0.0;
        foreach ($cuts as $sku => $n) {
            $freed += $n * ($sp['w'][$sku] ?? 0.0);
        }

        return ['move' => $move, 'cuts' => $cuts, 'cuts_freed' => $freed, 'occ' => $occ, 'facings' => $facings];
    }

    private function applySpace(array $sp, array $adds, array $delists, array $cuts): array
    {
        foreach ($cuts as $sku => $n) {
            $sp['f'][$sku] -= $n;
            $sp['occ'][$sku] -= $n * $sp['w'][$sku];
            $sp['used'] -= $n * $sp['w'][$sku];
            $sp['cut'][$sku] = ($sp['cut'][$sku] ?? 0) + $n;
        }
        foreach ($delists as $d) {
            $sku = (string) $d['sku'];
            $sp['used'] -= $sp['occ'][$sku] ?? 0.0;
            unset($sp['occ'][$sku], $sp['f'][$sku]);
            $sp['gone'][$sku] = true;
        }
        foreach ($adds as $a) {
            $sku = (string) $a['sku'];
            $e = $sp['entry'][$sku] ?? ['width' => $this->need($sp, $a), 'facings' => 1.0];
            $sp['w'][$sku] = (float) $e['width'];
            $sp['f'][$sku] = (float) $e['facings'];
            $sp['min'][$sku] = (float) $e['facings'];
            $sp['occ'][$sku] = (float) $e['width'] * (float) $e['facings'];
            $sp['used'] += $sp['occ'][$sku];
            $sp['noCut'][$sku] = true;
        }

        return $sp;
    }

    private function swapSpace(array $sp, array $out, array $in): array
    {
        $sku = (string) $out['sku'];
        $sp['used'] -= $sp['occ'][$sku] ?? 0.0;
        unset($sp['occ'][$sku], $sp['f'][$sku], $sp['noCut'][$sku]);

        return $this->applySpace($sp, [$in], [], []);
    }

    /** One row per product whose facings were cut, as a change of its own (kind facings). */
    private function cutRows(array $shelf, ?array $sp, array $cutStep): array
    {
        if ($sp === null || $sp['cut'] === []) {
            return [];
        }
        $rows = [];
        $total = max(self::EPS, $sp['sales_total']);
        foreach ($sp['cut'] as $sku => $n) {
            if (isset($sp['gone'][$sku])) {
                continue;   // cut, then delisted: the delist frees all of it
            }
            $p = $shelf['skus'][$sku];
            $from = (int) round((float) $p['facings']);
            $w = (float) $sp['w'][$sku];
            $freed = $n * $w;
            $spaceShare = ($w * $from) / max(self::EPS_M, $sp['used0']);
            $salesShare = ($sp['sales'][$sku] ?? 0.0) / $total;
            $rows[] = [
                'kind' => AssortmentPlan::FACINGS, 'key' => 'facings:' . $sku, 'step' => $cutStep[$sku] ?? 0, 'sku' => (string) $sku,
                'name' => (string) ($p['name'] ?? $sku), 'gap_id' => null, 'from' => $from, 'to' => $from - $n,
                'metres' => round(-$freed, 4), 'sales' => [0.0, 0.0, 0.0], 'margin' => [0.0, 0.0, 0.0], 'stock' => 0.0, 'availability' => 0.0,
                'tier' => null, 'confidence' => null, 'ticked' => true, 'source' => 'engine',
                'why' => (($cutStep[$sku] ?? 0) === 0 ? 'Needed to bring the shelf within its metres. ' : 'Makes room for the changes above. ')
                    . sprintf('Over-spaced: %d%% of the shelf for %d%% of the category\'s sales. %d → %d facings frees %.2f m. Assumed to lose no sales it needs; it keeps at least %d.',
                        (int) round($spaceShare * 100), (int) round($salesShare * 100), $from, $from - $n, $freed, (int) round((float) ($p['min_facings'] ?? 1))),
            ];
        }

        return $rows;
    }

    /** What the plan says about space: basis, metres, used before and after, and the assumptions made. */
    private function spaceInfo(array $shelf, ?array $sp): array
    {
        $space = $shelf['space'] ?? ['basis' => ShelfSpace::BASIS_COUNT, 'note' => null];
        if ($sp === null) {
            return ['basis' => ShelfSpace::BASIS_COUNT, 'note' => $space['note'] ?? null, 'metres' => $space['metres'] ?? null];
        }

        return [
            'basis' => $sp['level'], 'metres' => $sp['metres'], 'used_before' => round($sp['used0'], 4), 'used_after' => round($sp['used'], 4),
            'metres_source' => $space['metres_source'] ?? null, 'avg_facings' => $space['avg_facings'] ?? null,
            'widths_assumed' => $space['widths_assumed'] ?? 0, 'facings_assumed' => $space['facings_assumed'] ?? 0, 'note' => $space['note'] ?? null,
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
