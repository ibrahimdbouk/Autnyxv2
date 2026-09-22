<?php

namespace App\Services\Investigation;

use App\Models\AnomalySetting;
use App\Models\Investigation;
use App\Models\InvestigationEvidence;
use App\Models\InvestigationOutcome;
use App\Models\OutcomeMeasurement;
use App\Services\Anomaly\CausalGraph;
use App\Services\Anomaly\RootCauseAnalysisService;

/**
 * DEEP INVESTIGATION — read-only evidence-contract layer.
 *
 * An OPTIONAL, interactive drill-down that appends *below* the canonical
 * 7-question Root Cause investigation. It changes nothing about detection or the
 * 7-question narration — it only re-projects data those already produced into
 * five explorable modules:
 *
 *   • Cause Map          — the deterministic causal graph among this
 *                          investigation's actual anomalies (CausalGraph edges).
 *   • Investigation Trail — the real audit ledger + signal-detection events.
 *   • Confidence Explorer — the deterministic confidence, shown honestly with
 *                           the evidence that backs it. No new vocabulary.
 *   • Evidence Explorer   — the governed InvestigationEvidence rows, filterable.
 *   • Impact Explorer     — estimated value-at-risk kept visibly SEPARATE from
 *                           measured recovery (Outcome + OutcomeMeasurement).
 *
 * PRINCIPLES (non-negotiable):
 *   1. Invents nothing. Every value traces to a stored row. No AI here.
 *   2. Honest empty-states. When the governed source is absent, the module says
 *      so plainly ("evidence unavailable") rather than fabricating.
 *   3. Read-only. This service performs no writes.
 *
 * Confidence vocabulary is reused verbatim, never invented:
 *   - causal inference : correlated | likely | verified   (RootCauseAnalysisService)
 *   - per-signal       : established | probable | suspected | unknown (Anomaly)
 *
 * See claude/deep-investigation.md and claude/narration-quality-standard.md.
 */
class DeepInvestigationService
{
    public function __construct(private RootCauseAnalysisService $rootCause) {}

    /**
     * Assemble every module for an investigation.
     *
     * @return array{
     *   cause_map:array<string,mixed>, trail:array<string,mixed>,
     *   confidence:array<string,mixed>, evidence:array<string,mixed>,
     *   impact:array<string,mixed>
     * }
     */
    public function build(Investigation $investigation): array
    {
        return [
            'cause_map'  => $this->causeMap($investigation),
            'trail'      => $this->trail($investigation),
            'confidence' => $this->confidence($investigation),
            'evidence'   => $this->evidence($investigation),
            'impact'     => $this->impact($investigation),
        ];
    }

    // =========================================================================
    // MODULE 1 — CAUSE MAP
    // =========================================================================

    /**
     * The retail causal graph restricted to THIS investigation's anomalies. Nodes
     * are the actual signals; edges are the CausalGraph cause→effect links that
     * genuinely hold (same SKU / store / supplier per the edge's scope). The root
     * and tier come from the same deterministic inference the 7-question view uses.
     */
    private function causeMap(Investigation $investigation): array
    {
        $anomalies = $investigation->anomalies;

        if ($anomalies->isEmpty()) {
            return $this->unavailable('No signals are attached to this investigation, so there is nothing to map.');
        }

        $nodes = [];
        foreach ($anomalies as $a) {
            $nodes[] = [
                'id'        => $a->id,
                'rule'      => $a->rule_type,
                'label'     => $this->ruleLabel($a->rule_type),
                'sku'       => $a->sku,
                'store_id'  => $a->store_id,
                'severity'  => $a->severity,
                'impact'    => $a->estimatedImpact(),
                'lifecycle' => $a->lifecycle_state,
                'is_root'   => false,
            ];
        }

        // Directed causal edges that actually hold among these signals. Same walk
        // RootCauseAnalysisService performs — reusing CausalGraph as the single
        // source of domain knowledge, inventing no new relationships.
        $edges = [];
        foreach ($anomalies as $cause) {
            foreach ($anomalies as $effect) {
                if ($cause->id === $effect->id) {
                    continue;
                }
                $scope = CausalGraph::scopeFor($cause->rule_type, $effect->rule_type);
                if ($scope === null || ! $this->linked($scope, $cause, $effect)) {
                    continue;
                }
                $edges[] = [
                    'from'         => $cause->id,
                    'to'           => $effect->id,
                    'scope'        => $scope,
                    'scope_label'  => $this->scopeLabel($scope, $cause),
                    'cause_label'  => $this->ruleLabel($cause->rule_type),
                    'effect_label' => $this->ruleLabel($effect->rule_type),
                ];
            }
        }

        // Deterministic inference (root, tier, score, explanation). May be null
        // when there are fewer than two anomalies — the map still renders the node.
        $analysis = null;
        try {
            $analysis = $this->rootCause->analyze($investigation);
        } catch (\Throwable) {
            $analysis = null;
        }

        if ($analysis !== null) {
            foreach ($nodes as &$n) {
                if ($n['id'] === $analysis['root_anomaly_id']) {
                    $n['is_root'] = true;
                }
            }
            unset($n);
        }

        // Narrative honesty: signals present but no causal chain → correlated only.
        $structure = match (true) {
            $analysis !== null && ! empty($edges) => $analysis['tier'], // likely | verified
            count($nodes) >= 2                    => 'correlated',
            default                               => 'single',
        };

        return [
            'available'   => true,
            'empty_reason'=> null,
            'nodes'       => $nodes,
            'edges'       => $edges,
            'structure'   => $structure,
            'inference'   => $analysis === null ? null : [
                'tier'         => $analysis['tier'],
                'confidence'   => $analysis['confidence'],
                'links'        => $analysis['links'],
                'alternatives' => $analysis['alternatives'],
                'root_label'   => $analysis['root_label'],
                'explanation'  => $analysis['explanation'],
            ],
        ];
    }

    // =========================================================================
    // MODULE 2 — INVESTIGATION TRAIL
    // =========================================================================

    /**
     * A chronological trail of what actually happened: the append-only audit
     * ledger plus the signal-detection events that predate the investigation.
     * Every entry is a stored fact — nothing is inferred.
     */
    private function trail(Investigation $investigation): array
    {
        $events = [];

        // Signal detection — happened before the investigation ledger existed.
        foreach ($investigation->anomalies as $a) {
            $at = $a->detected_at ?? $a->first_seen_at ?? $a->created_at;
            if ($at !== null) {
                $events[] = [
                    'at'      => $at,
                    'kind'    => 'detected',
                    'icon'    => 'signal',
                    'actor'   => 'System',
                    'summary' => 'Signal detected — ' . $this->ruleLabel($a->rule_type)
                        . ($a->sku ? " · SKU {$a->sku}" : ''),
                ];
            }
            if ($a->lifecycle_state === \App\Models\Anomaly::LIFECYCLE_RESOLVED && $a->cleared_at) {
                $events[] = [
                    'at'      => $a->cleared_at,
                    'kind'    => 'cleared',
                    'icon'    => 'check',
                    'actor'   => 'System',
                    'summary' => 'Signal cleared — ' . $this->ruleLabel($a->rule_type),
                ];
            }
        }

        // The canonical audit ledger (append-only). Comments, actions, status
        // changes, AI generation and outcomes are all recorded here, so this is
        // the single non-duplicating source for everything post-detection.
        foreach ($investigation->auditLogs as $log) {
            $events[] = [
                'at'      => $log->created_at,
                'kind'    => $log->event_type,
                'icon'    => $this->trailIcon($log->event_type),
                'actor'   => $log->getActorLabel(),
                'summary' => $log->description ?: $this->eventFallback($log->event_type),
            ];
        }

        // Chronological, oldest first.
        usort($events, fn ($a, $b) => ($a['at'] <=> $b['at']));

        $out = array_map(fn ($e) => [
            'at'       => $e['at']?->toIso8601String(),
            'at_human' => $e['at']?->diffForHumans(),
            'at_label' => $e['at']?->format('M j, Y · H:i'),
            'kind'     => $e['kind'],
            'icon'     => $e['icon'],
            'actor'    => $e['actor'],
            'summary'  => $e['summary'],
        ], $events);

        return [
            'available'    => ! empty($out),
            'empty_reason' => empty($out) ? 'No recorded events yet for this investigation.' : null,
            'events'       => $out,
        ];
    }

    // =========================================================================
    // MODULE 3 — CONFIDENCE EXPLORER
    // =========================================================================

    /**
     * Explains the deterministic confidence honestly and shows the evidence that
     * backs it — two clearly separated tracks (causal-inference tier and
     * per-signal narration confidence), plus real supports/contradicts counts.
     * No confidence is computed here; existing values are surfaced.
     */
    private function confidence(Investigation $investigation): array
    {
        // Track A — causal inference (correlated | likely | verified).
        $analysis = null;
        try {
            $analysis = $this->rootCause->analyze($investigation);
        } catch (\Throwable) {
            $analysis = null;
        }
        $causal = $analysis === null ? null : [
            'tier'         => $analysis['tier'],
            'score'        => $analysis['confidence'],
            'links'        => $analysis['links'],
            'alternatives' => $analysis['alternatives'],
            'explanation'  => $analysis['explanation'],
        ];

        // Track B — per-signal narration confidence (established | probable |
        // suspected | unknown) and its evidence gate.
        $tiers = [
            \App\Models\Anomaly::CONFIDENCE_ESTABLISHED => 0,
            \App\Models\Anomaly::CONFIDENCE_PROBABLE    => 0,
            \App\Models\Anomaly::CONFIDENCE_SUSPECTED   => 0,
            \App\Models\Anomaly::CONFIDENCE_UNKNOWN     => 0,
        ];
        $gates = [];
        foreach ($investigation->anomalies as $a) {
            $c = $a->ai_confidence ?: \App\Models\Anomaly::CONFIDENCE_UNKNOWN;
            $tiers[$c] = ($tiers[$c] ?? 0) + 1;
            if ($a->ai_recommendation_gate) {
                $gates[$a->ai_recommendation_gate] = ($gates[$a->ai_recommendation_gate] ?? 0) + 1;
            }
        }
        $signalConfidence = [];
        foreach ($tiers as $tier => $count) {
            if ($count > 0) {
                $signalConfidence[] = ['tier' => $tier, 'label' => ucfirst($tier), 'count' => $count];
            }
        }

        // Track C — the evidence that backs the conclusion (real counts).
        $backing = ['supports' => 0, 'contradicts' => 0, 'neutral' => 0];
        $strength = ['strong' => 0, 'moderate' => 0, 'weak' => 0];
        foreach ($investigation->evidence as $e) {
            match ($e->direction) {
                InvestigationEvidence::DIRECTION_SUPPORTS    => $backing['supports']++,
                InvestigationEvidence::DIRECTION_CONTRADICTS => $backing['contradicts']++,
                default                                      => $backing['neutral']++,
            };
            if (isset($strength[$e->strength])) {
                $strength[$e->strength]++;
            }
        }

        $hasAnything = $causal !== null
            || ! empty($signalConfidence)
            || array_sum($backing) > 0;

        return [
            'available'    => $hasAnything,
            'empty_reason' => $hasAnything
                ? null
                : 'Confidence is established once the investigation is narrated and at least two signals are linked. Generate the narrative to populate this.',
            'causal'       => $causal,
            'signals'      => $signalConfidence,
            'gates'        => $gates,
            'backing'      => $backing,
            'strength'     => $strength,
        ];
    }

    // =========================================================================
    // MODULE 4 — EVIDENCE EXPLORER
    // =========================================================================

    /**
     * The governed evidence rows, projected for filtering by direction, source
     * and signal. Values are formatted exactly as the model formats them — this
     * is the same evidence the 7-question view cites, nothing new.
     */
    private function evidence(Investigation $investigation): array
    {
        $rows = $investigation->evidence;

        if ($rows->isEmpty()) {
            return $this->unavailable(
                'No evidence has been collected yet. Evidence is gathered automatically when the '
                . 'investigation is opened or its narrative is generated.'
            );
        }

        $ruleByAnomaly = $investigation->anomalies->keyBy('id');

        $items = $rows->map(function (InvestigationEvidence $e) use ($ruleByAnomaly) {
            $anomaly = $e->anomaly_id ? $ruleByAnomaly->get($e->anomaly_id) : null;

            return [
                'id'            => $e->id,
                'anomaly_id'    => $e->anomaly_id,
                'signal'        => $anomaly ? $this->ruleLabel($anomaly->rule_type) : 'Investigation',
                'source'        => $e->source,
                'label'         => $e->label,
                'value'         => $e->getFormattedValue(),
                'unit'          => $e->unit,
                'direction'     => $e->direction,
                'strength'      => $e->strength,
                'evidence_type' => $e->evidence_type,
                'observed_at'   => $e->observed_at?->format('M j, Y'),
            ];
        })->values()->all();

        return [
            'available'    => true,
            'empty_reason' => null,
            'count'        => count($items),
            'items'        => $items,
            'facets'       => [
                'directions' => array_values(array_unique(array_column($items, 'direction'))),
                'sources'    => array_values(array_filter(array_unique(array_column($items, 'source')))),
                'signals'    => array_values(array_unique(array_column($items, 'signal'))),
            ],
        ];
    }

    // =========================================================================
    // MODULE 5 — IMPACT EXPLORER
    // =========================================================================

    /**
     * Estimated value-at-risk (from each signal's context.revenue_impact) kept
     * visibly SEPARATE from measured recovery (InvestigationOutcome +
     * OutcomeMeasurement). An estimate is never presented as realised money.
     */
    private function impact(Investigation $investigation): array
    {
        $currency = $this->currencySymbol($investigation);

        // ── Estimate track — per-signal value at risk. ──────────────────────
        $breakdown = [];
        $totalAtRisk = 0.0;
        foreach ($investigation->anomalies as $a) {
            $amount = $a->estimatedImpact();
            if ($amount === null || $amount == 0.0) {
                continue;
            }
            $totalAtRisk += $amount;
            $breakdown[] = [
                'anomaly_id' => $a->id,
                'signal'     => $this->ruleLabel($a->rule_type),
                'sku'        => $a->sku,
                'store_id'   => $a->store_id,
                'amount'     => round($amount, 2),
            ];
        }
        usort($breakdown, fn ($x, $y) => $y['amount'] <=> $x['amount']);

        $aiEstimate = $investigation->revenue_at_risk !== null
            ? (float) $investigation->revenue_at_risk
            : null;

        // ── Measured track — only what has actually been measured. ──────────
        $measured = null;
        $outcome = $investigation->outcome;
        if ($outcome !== null && $outcome->outcome_state !== InvestigationOutcome::STATE_NOT_MEASURED) {
            $measured = [
                'state'              => $outcome->outcome_state,
                'state_label'        => InvestigationOutcome::STATE_LABELS[$outcome->outcome_state] ?? ucfirst((string) $outcome->outcome_state),
                'observed_recovery'  => $outcome->observed_recovery !== null ? (float) $outcome->observed_recovery : null,
                'cost_to_resolve'    => $outcome->cost_to_resolve !== null ? (float) $outcome->cost_to_resolve : null,
                'attribution_status' => $outcome->attribution_status,
                'attribution_label'  => InvestigationOutcome::ATTR_LABELS[$outcome->attribution_status] ?? null,
                'evidence_strength'  => $outcome->evidence_strength,
                'window_from'        => optional($outcome->measurement_window_start ?? $outcome->recovery_measured_from)?->format('M j, Y'),
                'window_to'          => optional($outcome->measurement_window_end ?? $outcome->recovery_measured_to)?->format('M j, Y'),
            ];
            if ($measured['observed_recovery'] !== null && $measured['cost_to_resolve'] !== null) {
                $measured['net'] = round($measured['observed_recovery'] - $measured['cost_to_resolve'], 2);
            }
        }

        // Per-metric deterministic measurements (append-only).
        $metrics = $investigation->outcomeMeasurements
            ->map(fn (OutcomeMeasurement $m) => [
                'metric'    => $m->getMetricLabel(),
                'baseline'  => $m->baseline_value,
                'expected'  => $m->expected_value,
                'observed'  => $m->observed_value,
                'delta'     => $m->delta_value,
                'recovery'  => $m->recovery_amount,
                'state'     => $m->outcome_state,
                'window'    => $m->window_start && $m->window_end
                    ? $m->window_start->format('M j') . ' – ' . $m->window_end->format('M j, Y')
                    : null,
            ])->values()->all();

        $available = $totalAtRisk > 0 || $aiEstimate !== null || $measured !== null || ! empty($metrics);

        return [
            'available'      => $available,
            'empty_reason'   => $available ? null : 'No monetary impact has been recorded for these signals yet.',
            'currency'       => $currency,
            'total_at_risk'  => round($totalAtRisk, 2),
            'ai_estimate'    => $aiEstimate,
            'breakdown'      => $breakdown,
            'measured'       => $measured,
            'metrics'        => $metrics,
            'note'           => 'Value at risk is an estimate (units at risk × price). It is not realised loss, and it is shown separately from any measured recovery.',
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /** Do two anomalies share the key a causal link's scope requires? */
    private function linked(string $scope, $a, $b): bool
    {
        $asku = $a->sku !== null ? trim($a->sku) : null;
        $bsku = $b->sku !== null ? trim($b->sku) : null;
        $asup = $a->context['supplier'] ?? null;
        $bsup = $b->context['supplier'] ?? null;

        return match ($scope) {
            CausalGraph::SCOPE_SKU       => $asku !== null && $asku === $bsku,
            CausalGraph::SCOPE_SKU_STORE => $asku !== null && $asku === $bsku
                                             && $a->store_id !== null && $a->store_id === $b->store_id,
            CausalGraph::SCOPE_STORE     => $a->store_id !== null && $a->store_id === $b->store_id,
            CausalGraph::SCOPE_SUPPLIER  => ! empty($asup) && $asup === $bsup,
            default                      => false,
        };
    }

    private function scopeLabel(string $scope, $cause): string
    {
        return match ($scope) {
            CausalGraph::SCOPE_SKU       => $cause->sku ? "same SKU ({$cause->sku})" : 'same SKU',
            CausalGraph::SCOPE_SKU_STORE => 'same SKU + store',
            CausalGraph::SCOPE_STORE     => 'same store',
            CausalGraph::SCOPE_SUPPLIER  => 'same supplier',
            default                      => $scope,
        };
    }

    private function ruleLabel(string $rule): string
    {
        return AnomalySetting::RULES[$rule]['label'] ?? ucwords(str_replace('_', ' ', $rule));
    }

    private function trailIcon(string $eventType): string
    {
        return match ($eventType) {
            'ai_generated'                       => 'sparkles',
            'action_created', 'action_completed' => 'action',
            'comment_added'                      => 'comment',
            'outcome_measured'                   => 'impact',
            'status_changed', 'priority_changed' => 'status',
            'assigned', 'reassigned', 'escalated'=> 'user',
            default                              => 'dot',
        };
    }

    private function eventFallback(string $eventType): string
    {
        return ucwords(str_replace('_', ' ', $eventType));
    }

    private function currencySymbol(Investigation $investigation): string
    {
        try {
            $tenant = $investigation->tenant;
            $code = $tenant?->currencyCode() ?? $tenant?->currency ?? null;

            return \App\Support\Money::symbol($code);
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return array{available:false, empty_reason:string} */
    private function unavailable(string $reason): array
    {
        return ['available' => false, 'empty_reason' => $reason];
    }
}
