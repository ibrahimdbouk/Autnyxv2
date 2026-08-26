<?php

namespace App\Services\Anomaly;

use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Models\Investigation;

/**
 * B8 — deterministic root-cause inference.
 *
 * Given an investigation (a group of co-occurring anomalies), tests them against
 * the retail causal graph and asserts the most likely ROOT CAUSE, the causal
 * CHAIN from it, and a CONFIDENCE tier:
 *
 *   correlated → the signals co-occur but form no known cause→effect chain.
 *   likely     → a direct cause→effect link is present.
 *   verified   → a full multi-step chain is present (cause → intermediate → effect).
 *
 * Everything is deterministic and explainable: the AI does not choose the cause,
 * the graph does. (Narration can later phrase this conclusion; it never invents it.)
 */
class RootCauseAnalysisService
{
    /**
     * @return array{
     *   root_anomaly_id:int, root_rule:string, root_label:string,
     *   chain:array<int,array{anomaly_id:int,rule:string,label:string,sku:?string,store_id:?int}>,
     *   tier:string, confidence:int, links:int, explanation:string, alternatives:int
     * }|null  null when there is nothing to infer (fewer than two anomalies).
     */
    public function analyze(Investigation $investigation): ?array
    {
        $anomalies = $investigation->anomalies()->get();
        if ($anomalies->count() < 2) return null;

        // Node info per anomaly.
        $nodes = [];
        foreach ($anomalies as $a) {
            $nodes[$a->id] = [
                'id'       => $a->id,
                'rule'     => $a->rule_type,
                'sku'      => $a->sku !== null ? trim($a->sku) : null,
                'store'    => $a->store_id,
                'supplier' => $a->context['supplier'] ?? null,
                'sev'      => $this->sevRank($a->severity),
                'impact'   => (float) ($a->context['revenue_impact'] ?? 0),
            ];
        }

        // Directed causal links among the actual anomalies.
        $links = [];                 // list of [causeId, effectId]
        $out   = array_fill_keys(array_keys($nodes), []);
        $indeg = array_fill_keys(array_keys($nodes), 0);
        foreach ($nodes as $ca) {
            foreach ($nodes as $ef) {
                if ($ca['id'] === $ef['id']) continue;
                $scope = CausalGraph::scopeFor($ca['rule'], $ef['rule']);
                if ($scope === null) continue;
                if (! $this->linked($scope, $ca, $ef)) continue;
                $links[] = [$ca['id'], $ef['id']];
                $out[$ca['id']][] = $ef['id'];
                $indeg[$ef['id']]++;
            }
        }

        // No causal structure → the signals merely co-occur.
        if (empty($links)) {
            $top = $this->mostSignificant($nodes);
            return [
                'root_anomaly_id' => $top['id'],
                'root_rule'       => $top['rule'],
                'root_label'      => $this->label($top['rule']),
                'chain'           => [$this->chainNode($top)],
                'tier'            => 'correlated',
                'confidence'      => 30,
                'links'           => 0,
                'alternatives'    => 0,
                'explanation'     => 'These signals co-occur on the same subject but form no known cause→effect chain — treat as correlated, not causally linked.',
            ];
        }

        // Root candidates: causes that are not themselves caused within the group.
        $roots = [];
        foreach ($nodes as $id => $n) {
            if ($indeg[$id] === 0 && ! empty($out[$id])) $roots[] = $id;
        }
        if (empty($roots)) {
            // Cyclic/degenerate — fall back to the highest out-degree node.
            $roots = [$this->maxBy(array_keys($nodes), fn ($id) => count($out[$id]))];
        }

        // Pick the root explaining the most (largest reachable set), then severity, then impact.
        $best = null; $bestReach = -1;
        foreach ($roots as $id) {
            $reach = $this->reachable($id, $out);
            $score = [count($reach), $nodes[$id]['sev'], $nodes[$id]['impact']];
            if ($best === null || $score > $bestScore) { $best = $id; $bestReach = count($reach); $bestScore = $score; }
        }

        $path  = $this->longestPath($best, $out);           // list of node ids
        $depth = count($path) - 1;                           // number of causal links along it

        $tier = $depth >= 2 ? 'verified' : 'likely';
        $confidence = match ($tier) {
            'verified' => min(95, 75 + 5 * ($depth - 2) + ($nodes[$best]['sev'] * 3)),
            default    => min(70, 55 + $nodes[$best]['sev'] * 3),
        };

        $chain = array_map(fn ($id) => $this->chainNode($nodes[$id]), $path);
        $explanation = $this->explain($chain, $tier);
        // Other independent chain heads (alternative root causes worth noting).
        $alternatives = max(0, count($roots) - 1);

        return [
            'root_anomaly_id' => $best,
            'root_rule'       => $nodes[$best]['rule'],
            'root_label'      => $this->label($nodes[$best]['rule']),
            'chain'           => $chain,
            'tier'            => $tier,
            'confidence'      => (int) $confidence,
            'links'           => count($links),
            'alternatives'    => $alternatives,
            'explanation'     => $explanation,
        ];
    }

    /** Do two anomalies share the key required by this link scope? */
    private function linked(string $scope, array $a, array $b): bool
    {
        return match ($scope) {
            CausalGraph::SCOPE_SKU       => $a['sku'] !== null && $a['sku'] === $b['sku'],
            CausalGraph::SCOPE_SKU_STORE => $a['sku'] !== null && $a['sku'] === $b['sku'] && $a['store'] !== null && $a['store'] === $b['store'],
            CausalGraph::SCOPE_STORE     => $a['store'] !== null && $a['store'] === $b['store'],
            CausalGraph::SCOPE_SUPPLIER  => ! empty($a['supplier']) && $a['supplier'] === $b['supplier'],
            default                      => false,
        };
    }

    /** Node ids reachable from $start (excluding itself). */
    private function reachable(int $start, array $out): array
    {
        $seen = [];
        $stack = $out[$start];
        while ($stack) {
            $id = array_pop($stack);
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            foreach ($out[$id] ?? [] as $next) $stack[] = $next;
        }

        return array_keys($seen);
    }

    /** Longest simple path from $start (DFS; graphs here are tiny). */
    private function longestPath(int $start, array $out, array $visited = []): array
    {
        $visited[$start] = true;
        $best = [$start];
        foreach ($out[$start] ?? [] as $next) {
            if (isset($visited[$next])) continue;
            $sub = $this->longestPath($next, $out, $visited);
            if (count($sub) + 1 > count($best)) $best = array_merge([$start], $sub);
        }

        return $best;
    }

    private function mostSignificant(array $nodes): array
    {
        $best = null;
        foreach ($nodes as $n) {
            $score = [$n['sev'], $n['impact']];
            if ($best === null || $score > $bestScore) { $best = $n; $bestScore = $score; }
        }

        return $best;
    }

    private function maxBy(array $ids, callable $f): int
    {
        $best = $ids[0]; $bv = $f($best);
        foreach ($ids as $id) { $v = $f($id); if ($v > $bv) { $bv = $v; $best = $id; } }

        return $best;
    }

    private function chainNode(array $n): array
    {
        return [
            'anomaly_id' => $n['id'],
            'rule'       => $n['rule'],
            'label'      => $this->label($n['rule']),
            'sku'        => $n['sku'],
            'store_id'   => $n['store'],
        ];
    }

    private function explain(array $chain, string $tier): string
    {
        $steps = implode(' → ', array_map(fn ($c) => $c['label'], $chain));
        $subject = $chain[0]['sku'] !== null ? " on SKU {$chain[0]['sku']}" : '';
        $lead = $tier === 'verified'
            ? 'A full causal chain is present'
            : 'A direct cause→effect link is present';

        return "{$lead}: {$steps}{$subject}. The head of the chain is the most likely root cause.";
    }

    private function label(string $rule): string
    {
        return AnomalySetting::RULES[$rule]['label'] ?? ucwords(str_replace('_', ' ', $rule));
    }

    private function sevRank(?string $sev): int
    {
        return match ($sev) {
            Anomaly::SEVERITY_HIGH   => 3,
            Anomaly::SEVERITY_MEDIUM => 2,
            default                  => 1,
        };
    }
}
