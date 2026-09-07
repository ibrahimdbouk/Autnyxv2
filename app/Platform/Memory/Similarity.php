<?php

namespace App\Platform\Memory;

/**
 * P4.3 — situation similarity. A situation is a numeric feature vector
 * ({feature: value}); cosine similarity over the union of keys (missing = 0)
 * gives a 0..1 score of how alike two situations are. Pure math, no dependency —
 * good enough to retrieve "similar past situations" without a vector database;
 * swap for pgvector/ANN later if the case base grows large.
 */
class Similarity
{
    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));

        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;

        foreach ($keys as $k) {
            $va = is_numeric($a[$k] ?? null) ? (float) $a[$k] : 0.0;
            $vb = is_numeric($b[$k] ?? null) ? (float) $b[$k] : 0.0;
            $dot += $va * $vb;
            $na += $va * $va;
            $nb += $vb * $vb;
        }

        if ($na == 0.0 || $nb == 0.0) {
            return 0.0;
        }

        return round($dot / (sqrt($na) * sqrt($nb)), 6);
    }
}
