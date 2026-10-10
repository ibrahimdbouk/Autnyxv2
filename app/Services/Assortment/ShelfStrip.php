<?php

namespace App\Services\Assortment;

use App\Models\AssortmentPlan;

/**
 * v2.1 — the schematic shelf strip: a category's shelf drawn as one strip as
 * long as its metres, each product a block as wide as facings × width, Today
 * against the Plan (or the Studio's workspace).
 *
 *   order       by subcategory, then sales — nothing in the data says where a
 *               product sits, so positions are NOT shown; the same order is used
 *               in both strips so they can be read against each other
 *   states      keep · add (new) · delist (goes) · cut (fewer facings) · grow (more)
 *   equal       shelf metres known but no product widths: equal blocks, said so
 *   none        no shelf metres for the store and category: no strip
 *
 * It is a schematic, not a planogram: no heights, no images, no positions.
 */
final class ShelfStrip
{
    public const KEEP = 'keep';
    public const ADD = 'add';
    public const DELIST = 'delist';
    public const CUT = 'cut';
    public const GROW = 'grow';

    public function __construct(private RangePlanner $planner, private ShelfSpace $space) {}

    /**
     * The strip for a range plan before its reset is done (afterwards the
     * store's range already shows the plan, so Today can no longer be drawn).
     *
     * @param  array<int,string>|null  $unticked  for a proposed plan: the changes the person has unticked
     */
    public function forPlan(AssortmentPlan $plan, ?array $unticked = null): ?array
    {
        if (! in_array($plan->status, [AssortmentPlan::STATUS_DRAFT, AssortmentPlan::STATUS_PROPOSED, AssortmentPlan::STATUS_ACCEPTED], true)
            || ! ShelfSpace::any((int) $plan->tenant_id)) {
            return null;
        }
        $tenantId = (int) $plan->tenant_id;
        $products = ProductFacts::load($tenantId, $plan->category);
        $asOf = ($plan->as_of_date ?? now())->toDateString();
        $shelf = $this->planner->shelvesFor($tenantId, (int) $plan->store_id, [$plan->category], $asOf, $products)[$plan->category] ?? null;
        if ($shelf === null) {
            return null;
        }

        $after = array_flip(array_map('strval', array_keys($shelf['skus'])));
        $adds = $facings = [];
        foreach ($plan->actionable() as $c) {
            $ticked = $unticked !== null ? ! in_array($c['key'], $unticked, true) : ($c['ticked'] ?? true);
            if (! $ticked) {
                continue;
            }
            $sku = (string) ($c['sku'] ?? '');
            match ($c['kind']) {
                AssortmentPlan::ADD => $adds[] = $sku,
                AssortmentPlan::DELIST => $after = array_diff_key($after, [$sku => true]),
                AssortmentPlan::SWAP => [$adds[] = $sku, $after = array_diff_key($after, [(string) ($c['out_sku'] ?? '') => true])],
                AssortmentPlan::FACINGS => $facings[$sku] = (int) $c['to'],
                default => null,
            };
        }
        foreach ($adds as $sku) {
            $after[$sku] = true;
        }
        $shelf = ShelfSpace::apply($shelf, $plan->category, $this->space->forStore($tenantId, (int) $plan->store_id), $adds, $products);

        return self::build($shelf, $products, array_keys($after), $facings);
    }

    /**
     * @param  array  $shelf  a shelf with its space (ShelfSpace::apply)
     * @param  array<int,string>  $after  the products on the shelf after the changes
     * @param  array<string,int>  $facingsAfter  facings set by the changes
     * @return array{basis:string, equal:bool, metres:float, scale:float, used_today:float, used_plan:float, today:array, plan:array, counts:array, note:?string}|null
     */
    public static function build(array $shelf, array $products, array $after, array $facingsAfter = []): ?array
    {
        $space = $shelf['space'] ?? null;
        $metres = (float) ($space['metres'] ?? 0);
        if ($space === null || $metres <= 0) {
            return null;
        }
        $after = array_values(array_unique(array_map('strval', $after)));
        $today = array_map('strval', array_keys($shelf['skus']));
        $equal = ($space['basis'] ?? ShelfSpace::BASIS_COUNT) === ShelfSpace::BASIS_COUNT;
        $unit = $equal ? $metres / max(1, count($today), count($after)) : null;

        $size = function (string $sku, bool $plan) use ($shelf, $space, $facingsAfter, $equal, $unit): array {
            if ($equal) {
                return [$unit, null];
            }
            $row = $shelf['skus'][$sku] ?? $space['entry'][$sku] ?? null;
            $w = (float) ($row['width'] ?? 0);
            $f = (float) ($row['facings'] ?? 1);
            if ($plan && isset($facingsAfter[$sku])) {
                $f = (float) $facingsAfter[$sku];
            }

            return [$w * $f, $f];
        };

        // One order for both strips: subcategory, then today's sales (new products after the rest of theirs).
        $sub = fn (string $sku) => (string) ($products[$sku]['subcategory'] ?? '') ?: 'Other';
        $all = array_values(array_unique(array_merge($today, $after)));
        usort($all, fn ($a, $b) => [$sub($a), -(float) ($shelf['skus'][$a]['sales'] ?? -1), $a] <=> [$sub($b), -(float) ($shelf['skus'][$b]['sales'] ?? -1), $b]);
        $onAfter = array_flip($after);
        $onToday = array_flip($today);

        $strip = function (bool $plan) use ($all, $onAfter, $onToday, $size, $sub, $products, $shelf): array {
            $out = [];
            $x = 0.0;
            foreach ($all as $sku) {
                if ($plan ? ! isset($onAfter[$sku]) : ! isset($onToday[$sku])) {
                    continue;
                }
                [$m, $f] = $size($sku, $plan);
                [$m0, $f0] = isset($onToday[$sku]) ? $size($sku, false) : [null, null];
                $state = self::KEEP;
                if (! $plan && ! isset($onAfter[$sku])) {
                    $state = self::DELIST;
                } elseif ($plan && ! isset($onToday[$sku])) {
                    $state = self::ADD;
                } elseif ($plan && $f !== null && $f0 !== null && round($f, 2) < round($f0, 2)) {
                    $state = self::CUT;
                } elseif ($plan && $f !== null && $f0 !== null && round($f, 2) > round($f0, 2)) {
                    $state = self::GROW;
                }
                $out[] = [
                    'sku' => $sku, 'name' => (string) (($products[$sku]['name'] ?? '') ?: ($shelf['skus'][$sku]['name'] ?? $sku)), 'sub' => $sub($sku),
                    'metres' => round($m, 4), 'facings' => $f === null ? null : (fmod($f, 1.0) === 0.0 ? (int) $f : round($f, 1)),
                    'facings_today' => $f0 === null ? null : (fmod($f0, 1.0) === 0.0 ? (int) $f0 : round($f0, 1)),
                    'state' => $state, 'x' => round($x, 4),
                ];
                $x += $m;
            }

            return [$out, round($x, 4)];
        };
        [$todayBlocks, $usedToday] = $strip(false);
        [$planBlocks, $usedPlan] = $strip(true);
        $count = fn (array $blocks, string $state) => count(array_filter($blocks, fn ($b) => $b['state'] === $state));

        return [
            'basis'      => (string) ($space['basis'] ?? ShelfSpace::BASIS_COUNT),
            'equal'      => $equal,
            'metres'     => round($metres, 2),
            'scale'      => max($metres, $usedToday, $usedPlan),
            'used_today' => $usedToday,
            'used_plan'  => $usedPlan,
            'today'      => $todayBlocks,
            'plan'       => $planBlocks,
            'counts'     => ['add' => $count($planBlocks, self::ADD), 'delist' => $count($todayBlocks, self::DELIST),
                'cut' => $count($planBlocks, self::CUT), 'grow' => $count($planBlocks, self::GROW)],
            'note'       => $equal ? 'No product widths: each product is drawn as an equal block.' : ($space['note'] ?? null),
        ];
    }
}
