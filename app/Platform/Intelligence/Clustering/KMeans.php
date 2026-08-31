<?php

namespace App\Platform\Intelligence\Clustering;

/**
 * A small, dependency-free, DETERMINISTIC k-means (Platform\Intelligence).
 *
 * Deterministic on two axes so clusters are stable night-to-night and testable:
 * k-means++-style seeding picks the most separated points (ties → lowest index),
 * and assignment breaks ties to the lowest centroid index. No randomness.
 *
 * Operates on already-standardised vectors — callers z-score their features so no
 * single dimension dominates the Euclidean distance.
 */
class KMeans
{
    /**
     * @param  list<list<float>>  $vectors  equal-length numeric vectors
     * @return array{assignments: list<int>, centroids: list<list<float>>}
     *         assignments[i] is the cluster index of vectors[i]
     */
    public static function cluster(array $vectors, int $k, int $maxIter = 25): array
    {
        $n = count($vectors);
        if ($n === 0) {
            return ['assignments' => [], 'centroids' => []];
        }

        $k = max(1, min($k, $n));
        $dim = count($vectors[0]);
        $centroids = self::seed($vectors, $k, $dim);

        $assign = array_fill(0, $n, -1);

        for ($iter = 0; $iter < $maxIter; $iter++) {
            $changed = false;

            // Assign each point to the nearest centroid (ties → lowest index).
            for ($i = 0; $i < $n; $i++) {
                $best = 0;
                $bestD = INF;
                foreach ($centroids as $c => $centroid) {
                    $d = self::dist2($vectors[$i], $centroid);
                    if ($d < $bestD) {
                        $bestD = $d;
                        $best = $c;
                    }
                }
                if ($assign[$i] !== $best) {
                    $assign[$i] = $best;
                    $changed = true;
                }
            }

            // Recompute centroids as the mean of their members; empty centroids stay put.
            $sums = array_fill(0, count($centroids), array_fill(0, $dim, 0.0));
            $counts = array_fill(0, count($centroids), 0);
            for ($i = 0; $i < $n; $i++) {
                $c = $assign[$i];
                $counts[$c]++;
                for ($d = 0; $d < $dim; $d++) {
                    $sums[$c][$d] += $vectors[$i][$d];
                }
            }
            foreach ($centroids as $c => $_) {
                if ($counts[$c] > 0) {
                    for ($d = 0; $d < $dim; $d++) {
                        $centroids[$c][$d] = $sums[$c][$d] / $counts[$c];
                    }
                }
            }

            if (! $changed) {
                break;
            }
        }

        return ['assignments' => $assign, 'centroids' => $centroids];
    }

    /** k-means++-style deterministic seeding: farthest-from-mean, then farthest-from-chosen. */
    private static function seed(array $vectors, int $k, int $dim): array
    {
        $n = count($vectors);

        $mean = array_fill(0, $dim, 0.0);
        foreach ($vectors as $v) {
            for ($d = 0; $d < $dim; $d++) {
                $mean[$d] += $v[$d] / $n;
            }
        }

        // First seed: the most extreme point (farthest from the mean).
        $first = 0;
        $firstD = -1.0;
        for ($i = 0; $i < $n; $i++) {
            $d = self::dist2($vectors[$i], $mean);
            if ($d > $firstD) {
                $firstD = $d;
                $first = $i;
            }
        }

        $chosen = [$first];
        while (count($chosen) < $k) {
            $best = -1;
            $bestMin = -1.0;
            for ($i = 0; $i < $n; $i++) {
                if (in_array($i, $chosen, true)) {
                    continue;
                }
                $minD = INF;
                foreach ($chosen as $c) {
                    $d = self::dist2($vectors[$i], $vectors[$c]);
                    if ($d < $minD) {
                        $minD = $d;
                    }
                }
                if ($minD > $bestMin) {
                    $bestMin = $minD;
                    $best = $i;
                }
            }
            if ($best < 0) {
                break;
            }
            $chosen[] = $best;
        }

        return array_map(fn ($i) => $vectors[$i], $chosen);
    }

    private static function dist2(array $a, array $b): float
    {
        $sum = 0.0;
        for ($i = 0, $len = count($a); $i < $len; $i++) {
            $x = $a[$i] - $b[$i];
            $sum += $x * $x;
        }

        return $sum;
    }
}
