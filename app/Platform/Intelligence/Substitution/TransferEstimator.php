<?php

namespace App\Platform\Intelligence\Substitution;

/**
 * Transferable demand for ONE product at ONE store's shelf: of product A's
 * demand, the share that goes to (or comes from) the products on that shelf,
 * as a low–mid–high range, with where each figure came from.
 *
 *   observed    a demand_transfers row for the pair (stockouts / range changes),
 *               blended with the similarity assumption by sample size, so thin
 *               evidence stays close to the assumption and strong evidence wins
 *   assumed     the similarity assumption alone: the share of A's buyers with
 *               an acceptable alternative is 1 − Π(1 − ½·closeness) over the
 *               shelf (at most 80%), split across the shelf by closeness
 *   insufficient  no observation and the product file has no subcategory or
 *               brand to judge closeness by: no number is invented; callers show
 *               the gross value only
 *
 * The same figure serves an add (the share of the new product's sales taken
 * from the shelf) and a delist or a stockout (the share that moves to the shelf
 * instead of being lost): both are "where A's buyers go when A is not there".
 * For an add the pair figures come from pool stores that DO carry A, with the
 * stated assumption that buyers switch the same way here.
 */
final class TransferEstimator
{
    public const PRIOR_SE     = 0.20;   // how loosely the similarity assumption is held
    public const PRIOR_CAP    = 0.80;   // the assumption never says more than 80% moves
    public const TOTAL_CAP    = 0.95;   // some demand is always lost or new
    public const Z            = 1.28;   // 80% interval

    public const BASIS_OBSERVED     = 'observed';
    public const BASIS_ASSUMED      = 'assumed';
    public const BASIS_INSUFFICIENT = 'insufficient';

    /**
     * @param  string  $sku  product A
     * @param  array<int,string>  $shelf  products on the store's shelf (A excluded)
     * @param  array<string,array<string,mixed>>  $products  sku => facts (category, subcategory, brand, pack_size, price, name)
     * @param  array<string,array<string,mixed>>  $observed  to_sku => observed row, for A in this pool
     * @return array<string,mixed>
     */
    public function estimate(string $sku, array $shelf, array $products, array $observed = []): array
    {
        $a = $products[$sku] ?? null;
        if ($a === null) {
            return $this->insufficient('The product is not on the product file.');
        }

        $close = [];
        foreach ($shelf as $b) {
            $b = (string) $b;
            if ($b === $sku || ! isset($products[$b])) {
                continue;
            }
            $c = ProductSimilarity::closeness($a, $products[$b]);
            if ($c > 0 || isset($observed[$b])) {
                $close[$b] = $c;
            }
        }
        $describable = ProductSimilarity::describable($a);
        $observedHere = array_intersect_key($observed, $close);

        if ($close === [] || (! $describable && $observedHere === [])) {
            return $close === [] && $describable
                ? $this->none()
                : $this->insufficient('No stockouts or range changes show where its buyers go, and the product file has no subcategory or brand to judge similar products by.');
        }

        // The similarity assumption over this shelf.
        $keep = 1.0;
        foreach ($close as $c) {
            $keep *= 1 - 0.5 * $c;
        }
        $assumedTotal = $describable ? min(self::PRIOR_CAP, 1 - $keep) : 0.0;
        $sumC = array_sum($close);

        $pairs = [];
        foreach ($close as $b => $c) {
            $prior = $sumC > 0 ? $assumedTotal * $c / $sumC : 0.0;
            $o = $observedHere[$b] ?? null;
            if ($o !== null) {
                $se = max(0.02, (float) $o['se']);
                $w1 = 1 / ($se ** 2);
                $w0 = $describable ? 1 / (self::PRIOR_SE ** 2) : 0.0;
                $mid = ($w1 * (float) $o['share'] + $w0 * $prior) / ($w1 + $w0);
                $pse = 1 / sqrt($w1 + $w0);
                $pairs[$b] = [
                    'sku' => $b, 'basis' => self::BASIS_OBSERVED, 'evidence' => $o['basis'],
                    'low' => max(0.0, $mid - self::Z * $pse), 'mid' => $mid, 'high' => min(1.0, $mid + self::Z * $pse),
                    'stores' => (int) $o['stores'], 'store_days' => (int) $o['store_days'], 'events' => (int) $o['events'],
                    'version' => $o['version'] ?? DemandTransferService::VERSION,
                ];
            } elseif ($prior > 0) {
                $pairs[$b] = [
                    'sku' => $b, 'basis' => self::BASIS_ASSUMED, 'evidence' => 'similarity',
                    'low' => $prior * 0.5, 'mid' => $prior, 'high' => min(1.0, $prior * 1.5),
                    'shared' => ProductSimilarity::shared($a, $products[$b]),
                    'stores' => 0, 'store_days' => 0, 'events' => 0,
                ];
            }
        }
        if ($pairs === []) {
            return $this->none();
        }

        $mid = array_sum(array_column($pairs, 'mid'));
        if ($mid > self::TOTAL_CAP) {
            $scale = self::TOTAL_CAP / $mid;
            foreach ($pairs as $b => $p) {
                foreach (['low', 'mid', 'high'] as $k) {
                    $pairs[$b][$k] = $p[$k] * $scale;
                }
            }
            $mid = self::TOTAL_CAP;
        }
        $low  = min($mid, array_sum(array_column($pairs, 'low')));
        $high = max($mid, min(self::TOTAL_CAP, array_sum(array_column($pairs, 'high'))));

        $observedMid = array_sum(array_map(fn ($p) => $p['basis'] === self::BASIS_OBSERVED ? $p['mid'] : 0.0, $pairs));
        $basis = $observedMid >= 0.5 * $mid && $observedMid > 0 ? self::BASIS_OBSERVED : self::BASIS_ASSUMED;
        uasort($pairs, fn ($x, $y) => $y['mid'] <=> $x['mid']);

        $obs = array_filter($pairs, fn ($p) => $p['basis'] === self::BASIS_OBSERVED);

        return [
            'basis'      => $basis,
            'low'        => round($low, 4),
            'mid'        => round($mid, 4),
            'high'       => round($high, 4),
            'tier'       => $this->tier($basis, $obs),
            'stores'     => $obs === [] ? 0 : max(array_column($obs, 'stores')),
            'store_days' => array_sum(array_column($obs, 'store_days')),
            'events'     => array_sum(array_column($obs, 'events')),
            'pairs'      => array_map(fn ($p) => array_merge($p, [
                'name' => (string) ($products[$p['sku']]['name'] ?? $p['sku']),
                'low'  => round($p['low'], 4), 'mid' => round($p['mid'], 4), 'high' => round($p['high'], 4),
            ]), array_slice(array_values($pairs), 0, 5)),
            'version'    => DemandTransferService::VERSION,
            'note'       => null,
        ];
    }

    /** Established / likely / speculative for observed figures; "assumed" otherwise. */
    private function tier(string $basis, array $observed): string
    {
        if ($basis !== self::BASIS_OBSERVED) {
            return 'assumed';
        }
        $days = array_sum(array_column($observed, 'store_days')) + 10 * array_sum(array_column($observed, 'events'));
        $stores = $observed === [] ? 0 : max(array_column($observed, 'stores'));

        return match (true) {
            $days >= 200 && $stores >= 8 => 'established',
            $days >= 40 && $stores >= 3  => 'likely',
            default                      => 'speculative',
        };
    }

    /** Nothing on the shelf is like it: none of its demand moves, none of a new one's is taken. */
    private function none(): array
    {
        return ['basis' => self::BASIS_ASSUMED, 'low' => 0.0, 'mid' => 0.0, 'high' => 0.0, 'tier' => 'assumed',
            'stores' => 0, 'store_days' => 0, 'events' => 0, 'pairs' => [], 'version' => DemandTransferService::VERSION,
            'note' => 'Nothing similar is on this shelf.'];
    }

    private function insufficient(string $why): array
    {
        return ['basis' => self::BASIS_INSUFFICIENT, 'low' => null, 'mid' => null, 'high' => null, 'tier' => null,
            'stores' => 0, 'store_days' => 0, 'events' => 0, 'pairs' => [], 'version' => DemandTransferService::VERSION,
            'note' => $why];
    }
}
