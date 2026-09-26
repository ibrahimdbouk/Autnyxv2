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
 *   correlated   → the signals co-occur but form no known cause→effect chain.
 *   likely       → a direct cause→effect link is present.
 *   corroborated → a full multi-step chain is present (cause → intermediate →
 *                  effect). (Was "verified" — WP4.5: the data corroborates the
 *                  chain; nothing has been verified in the field.)
 *
 * WP4.5 (audit H23): only LIVE anomalies are analysed (a dismissed or resolved
 * signal is not part of today's cause), and a link that needs the same store
 * holds when one side is chain-level — it is then matched on the SKU.
 *
 * W13 — timing: a cause has to come before its effect in the data. Each
 * signal's start is read from the data (SignalOnset); a link whose cause
 * certainly started after its effect was under way is dropped, a link whose
 * order the data confirms is marked "in order", and one the data cannot
 * settle stays, marked "timing not confirmed". A chain whose every link is in
 * order earns a little more confidence.
 *
 * Everything is deterministic and explainable: the AI does not choose the cause,
 * the graph does. (Narration can later phrase this conclusion; it never invents it.)
 */
class RootCauseAnalysisService
{
    public const TIER_CORROBORATED = 'corroborated';

    /** A day's grace: files land on different nights. */
    public const TIMING_TOLERANCE_DAYS = 1;

    public const TIMING_IN_ORDER   = 'in_order';
    public const TIMING_UNVERIFIED = 'unverified';
    public const TIMING_REVERSED   = 'reversed';

    /** Tier → the investigation confidence scale the UI and API already use. */
    public const CONFIDENCE_FOR_TIER = [
        self::TIER_CORROBORATED => 'established',
        'likely'                => 'probable',
        'correlated'            => 'suspected',
    ];

    /**
     * WP4.5: store the deterministic cause on the investigation — the narrator
     * phrases it, the KPIs count it, the AI never chooses it.
     *
     * @return array<string,mixed>|null the analysis
     */
    public function record(Investigation $investigation): ?array
    {
        $analysis = $this->analyze($investigation);
        $tier = $analysis['tier'] ?? ($investigation->anomalies()->active()->exists() ? 'single' : null);

        $investigation->update([
            'root_cause_tier' => $tier,
            'root_cause_rule' => $analysis['root_rule']
                ?? $investigation->anomalies()->active()->orderByDesc('id')->value('rule_type'),
            'ai_confidence'   => self::CONFIDENCE_FOR_TIER[$tier] ?? 'unknown',
        ]);

        return $analysis;
    }

    /**
     * @return array{
     *   root_anomaly_id:int, root_rule:string, root_label:string,
     *   chain:array<int,array{anomaly_id:int,rule:string,label:string,sku:?string,store_id:?int}>,
     *   tier:string, confidence:int, links:int, explanation:string, alternatives:int
     * }|null  null when there is nothing to infer (fewer than two anomalies).
     */
    public function analyze(Investigation $investigation): ?array
    {
        $anomalies = $investigation->anomalies()->active()->get();
        if ($anomalies->count() < 2) return null;

        // Node info per anomaly.
        $nodes = [];
        $onsets = new SignalOnset;
        foreach ($anomalies as $a) {
            $onset = $onsets->of($a);
            $nodes[$a->id] = [
                'id'       => $a->id,
                'rule'     => $a->rule_type,
                'sku'      => $a->sku !== null ? trim($a->sku) : null,
                'store'    => $a->store_id,
                'supplier' => $a->context['supplier'] ?? null,
                'sev'      => $this->sevRank($a->severity),
                'impact'   => (float) ($a->context['revenue_impact'] ?? 0),
                'onset'    => $onset,
            ];
        }

        // Directed causal links among the actual anomalies.
        $links = [];                 // list of [causeId, effectId]
        $timing = [];                // "cause-effect" => in_order | unverified
        $reversed = [];              // links dropped: the cause started after the effect
        $out   = array_fill_keys(array_keys($nodes), []);
        $indeg = array_fill_keys(array_keys($nodes), 0);
        foreach ($nodes as $ca) {
            foreach ($nodes as $ef) {
                if ($ca['id'] === $ef['id']) continue;
                $scope = CausalGraph::scopeFor($ca['rule'], $ef['rule']);
                if ($scope === null) continue;
                if (! $this->linked($scope, $ca, $ef)) continue;
                $order = self::order($ca['onset'], $ef['onset']);
                if ($order === self::TIMING_REVERSED) {
                    $reversed[] = ['cause_id' => $ca['id'], 'effect_id' => $ef['id'], 'cause' => $this->label($ca['rule']),
                        'effect' => $this->label($ef['rule']), 'cause_from' => $ca['onset']['earliest'], 'effect_by' => $ef['onset']['latest']];
                    continue;
                }
                $timing[$ca['id'] . '-' . $ef['id']] = $order;
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
                'timing'          => ['in_order' => 0, 'unverified' => 0, 'reversed' => count($reversed)],
                'reversed'        => $reversed,
                'explanation'     => $reversed
                    ? 'These signals would form a cause→effect chain, but the data shows the supposed cause started after the effect was already under way ('
                        . $this->reversedText($reversed[0]) . ') — treat as correlated, not causally linked.'
                    : 'These signals co-occur on the same subject but form no known cause→effect chain — treat as correlated, not causally linked.',
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

        $tier = $depth >= 2 ? self::TIER_CORROBORATED : 'likely';
        $confidence = match ($tier) {
            self::TIER_CORROBORATED => min(95, 75 + 5 * ($depth - 2) + ($nodes[$best]['sev'] * 3)),
            default    => min(70, 55 + $nodes[$best]['sev'] * 3),
        };

        $chain = array_map(fn ($id) => $this->chainNode($nodes[$id]), $path);
        $pathTiming = [];
        for ($i = 1; $i < count($path); $i++) {
            $pathTiming[] = $timing[$path[$i - 1] . '-' . $path[$i]] ?? self::TIMING_UNVERIFIED;
            $chain[$i]['timing'] = end($pathTiming);
        }
        $allInOrder = $pathTiming !== [] && ! in_array(self::TIMING_UNVERIFIED, $pathTiming, true);
        if ($allInOrder) {
            $confidence = min($tier === self::TIER_CORROBORATED ? 95 : 70, $confidence + 5);
        }
        $explanation = $this->explain($chain, $tier) . $this->timingText($chain, $allInOrder, $reversed);
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
            'timing'          => [
                'in_order'   => count(array_filter($timing, fn ($t) => $t === self::TIMING_IN_ORDER)),
                'unverified' => count(array_filter($timing, fn ($t) => $t === self::TIMING_UNVERIFIED)),
                'reversed'   => count($reversed),
            ],
            'reversed'        => $reversed,
            'explanation'     => $explanation,
        ];
    }

    /**
     * Does the cause come before the effect?
     *   reversed    the cause cannot have started until after the effect was under way
     *   in_order    the cause was under way by the time the effect could have started
     *   unverified  the data cannot settle it
     *
     * @param array{earliest:?string, latest:?string} $cause
     * @param array{earliest:?string, latest:?string} $effect
     */
    public static function order(array $cause, array $effect): string
    {
        $tol = self::TIMING_TOLERANCE_DAYS;
        if ($cause['earliest'] !== null && $effect['latest'] !== null
            && \Illuminate\Support\Carbon::parse($cause['earliest'])->gt(\Illuminate\Support\Carbon::parse($effect['latest'])->addDays($tol))) {
            return self::TIMING_REVERSED;
        }
        if ($cause['latest'] !== null && $effect['earliest'] !== null
            && \Illuminate\Support\Carbon::parse($cause['latest'])->lte(\Illuminate\Support\Carbon::parse($effect['earliest'])->addDays($tol))) {
            return self::TIMING_IN_ORDER;
        }

        return self::TIMING_UNVERIFIED;
    }

    private function timingText(array $chain, bool $allInOrder, array $reversed): string
    {
        $text = '';
        if ($allInOrder) {
            $steps = array_map(fn ($c) => $c['label'] . ' from ' . $c['onset'], $chain);
            $text .= ' The timing checks out: ' . implode(', then ', $steps) . '.';
        } elseif (count($chain) > 1) {
            $text .= ' The data does not settle the order of every step, so the timing is not confirmed.';
        }
        if ($reversed) {
            $text .= ' ' . count($reversed) . ' possible link(s) were left out because the supposed cause started after the effect ('
                . $this->reversedText($reversed[0]) . ').';
        }

        return $text;
    }

    private function reversedText(array $r): string
    {
        return "{$r['cause']} from {$r['cause_from']}, {$r['effect']} already by {$r['effect_by']}";
    }

    /** Do two anomalies share the key required by this link scope? */
    private function linked(string $scope, array $a, array $b): bool
    {
        return match ($scope) {
            CausalGraph::SCOPE_SKU       => $a['sku'] !== null && $a['sku'] === $b['sku'],
            // Same shelf — or the same SKU when either side is chain-level (no store).
            CausalGraph::SCOPE_SKU_STORE => $a['sku'] !== null && $a['sku'] === $b['sku']
                && ($a['store'] === null || $b['store'] === null || $a['store'] === $b['store']),
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
            'onset'      => $n['onset']['latest'],
            'onset_basis'=> $n['onset']['basis'],
            'onset_source' => $n['onset']['source'],
            'timing'     => null,
        ];
    }

    private function explain(array $chain, string $tier): string
    {
        $steps = implode(' → ', array_map(fn ($c) => $c['label'], $chain));
        $subject = $chain[0]['sku'] !== null ? " on SKU {$chain[0]['sku']}" : '';
        $lead = $tier === self::TIER_CORROBORATED
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
