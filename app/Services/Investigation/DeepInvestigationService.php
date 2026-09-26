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
 *   - causal inference : correlated | likely | corroborated   (RootCauseAnalysisService)
 *   - per-signal       : established | probable | suspected | unknown (Anomaly)
 *
 * See claude/deep-investigation.md and claude/narration-quality-standard.md.
 */
class DeepInvestigationService
{
    public function __construct(
        private RootCauseAnalysisService $rootCause,
        private \App\Services\Anomaly\ReplenishmentRecommendationService $recommendations,
        private ProjectionService $projections,
    ) {}

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
            'cause_map'     => $this->causeMap($investigation),
            'what_changed'  => $this->whatChanged($investigation),
            'why_rec'       => $this->whyRecommendation($investigation),
            'what_if'       => $this->whatIf($investigation),
            'similar'       => $this->similarIncidents($investigation),
            'trail'         => $this->trail($investigation),
            'confidence'    => $this->confidence($investigation),
            'evidence'      => $this->evidence($investigation),
            'impact'        => $this->impact($investigation),
        ];
    }

    // =========================================================================
    // MODULE — WHAT-IF / ACTION SIMULATOR (deterministic projection, SIMULATED)
    // =========================================================================

    private function whatIf(Investigation $investigation): array
    {
        try {
            $p = $this->projections->forInvestigation($investigation);
            $p['currency'] = $this->currencySymbol($investigation);

            return $p;
        } catch (\Throwable) {
            return ['available' => false, 'empty_reason' => 'Evidence unavailable.', 'simulated' => true];
        }
    }

    // =========================================================================
    // MODULE — SIMILAR INCIDENTS (resolved corpus; honest when thin)
    // =========================================================================

    /**
     * Prior resolved/closed investigations that share a signal type or SKU, with
     * the analyst-recorded outcome. Nothing is fabricated — it reflects only what
     * has actually been resolved through Autnyx, and says so when the corpus is thin.
     */
    private function similarIncidents(Investigation $investigation): array
    {
        $rules = $investigation->anomalies->pluck('rule_type')->unique()->all();
        $sku = $investigation->primary_sku;

        $candidates = Investigation::where('tenant_id', $investigation->tenant_id)
            ->where('id', '!=', $investigation->id)
            ->whereIn('status', [Investigation::STATUS_RESOLVED, Investigation::STATUS_CLOSED])
            ->with(['outcome', 'anomalies:id,investigation_id,rule_type', 'actions'])
            ->latest('resolved_at')
            ->limit(60)
            ->get();

        $currency = $this->currencySymbol($investigation);
        $matches = [];
        foreach ($candidates as $c) {
            $shared = array_values(array_intersect($rules, $c->anomalies->pluck('rule_type')->unique()->all()));
            if (! empty($shared)) {
                $reason = 'Same signal · ' . $this->ruleLabel($shared[0]);
            } elseif ($sku && $c->primary_sku && $c->primary_sku === $sku) {
                $reason = 'Same SKU · ' . $sku;
            } else {
                continue;
            }

            $o = $c->outcome;
            $matches[] = [
                'id'          => $c->id,
                'title'       => $c->title,
                'resolved_at' => optional($c->resolved_at ?? $c->closed_at)?->format('M j, Y'),
                'match'       => $reason,
                'outcome'     => $o ? (InvestigationOutcome::TYPE_LABELS[$o->outcome_type] ?? ucfirst((string) $o->outcome_type)) : null,
                'recovery'    => ($o && $o->observed_recovery !== null) ? $currency . number_format((float) $o->observed_recovery, 0) : null,
                'root_cause'  => $o?->confirmed_root_cause,
                // The borrowable playbook: what the team actually did on the prior
                // incident that resolved it — the completed action if there is one,
                // else the highest-priority action they recorded.
                'playbook'    => $this->siblingPlaybook($c),
            ];
            if (count($matches) >= 5) {
                break;
            }
        }

        if (empty($matches)) {
            return $this->unavailable('No comparable resolved investigation yet. This builds up as more investigations with the same signal type or SKU are resolved through Autnyx.');
        }

        return [
            'available'    => true,
            'empty_reason' => null,
            'items'        => $matches,
            'note'         => 'Matched on shared signal type or SKU among resolved / closed investigations. Recovery figures and actions are what analysts recorded, not projections.',
        ];
    }

    /**
     * The one action from a resolved sibling worth borrowing: the completed action
     * (what actually worked) if there is one, otherwise the highest-priority action
     * the team recorded. Null when the sibling logged no action. Governed rows only.
     */
    private function siblingPlaybook(Investigation $sibling): ?array
    {
        $actions = $sibling->actions ?? collect();
        if ($actions->isEmpty()) {
            return null;
        }

        $completed = $actions->firstWhere('status', \App\Models\Action::STATUS_COMPLETED);
        $act = $completed ?? $actions->sortBy(fn ($a) => $this->priorityRank($a->priority))->first();
        if ($act === null) {
            return null;
        }

        return [
            'title' => $act->title,
            'kind'  => \App\Models\Action::TYPE_LABELS[$act->action_type] ?? ucwords(str_replace('_', ' ', (string) ($act->action_type ?? 'action'))),
            'done'  => $completed !== null,
        ];
    }

    private function priorityRank(?string $priority): int
    {
        return match ($priority) {
            \App\Models\Action::PRIORITY_CRITICAL => 0,
            \App\Models\Action::PRIORITY_HIGH     => 1,
            \App\Models\Action::PRIORITY_MEDIUM   => 2,
            \App\Models\Action::PRIORITY_LOW      => 3,
            default                               => 4,
        };
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

        // Evidence grouped per signal — powers the click-through detail drawer,
        // reusing the governed InvestigationEvidence rows (no new queries).
        $evidenceByAnomaly = $investigation->evidence->groupBy('anomaly_id');
        $currency = $this->currencySymbol($investigation);

        $nodes = [];
        foreach ($anomalies as $a) {
            $nodes[$a->id] = [
                'id'        => $a->id,
                'rule'      => $a->rule_type,
                'label'     => $this->ruleLabel($a->rule_type),
                'sku'       => $a->sku,
                'store_id'  => $a->store_id,
                'severity'  => $a->severity ?: 'low',
                'impact'    => $a->estimatedImpact(),
                'lifecycle' => $a->lifecycle_state,
                'category'  => $this->categoryOf($a->rule_type),
                'is_root'   => false,
                'detail'    => $this->nodeDetail($a, $evidenceByAnomaly->get($a->id), $currency),
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

        // W13: a link whose cause started after its effect is not a link.
        $reversed = collect($analysis['reversed'] ?? [])->map(fn ($r) => $r['cause_id'] . '-' . $r['effect_id'])->flip();
        $edges = array_values(array_filter($edges, fn ($e) => ! isset($reversed[$e['from'] . '-' . $e['to']])));

        $rootId = $analysis['root_anomaly_id'] ?? null;
        if ($rootId !== null && isset($nodes[$rootId])) {
            $nodes[$rootId]['is_root'] = true;
        }

        // Narrative honesty: signals present but no causal chain → correlated only.
        $structure = match (true) {
            $analysis !== null && ! empty($edges) => $analysis['tier'], // likely | corroborated
            count($nodes) >= 2                    => 'correlated',
            default                               => 'single',
        };

        $head = $this->causeHead($analysis, $nodes, $structure);
        $categories = $this->fishboneCategories($nodes);

        return [
            'available'    => true,
            'empty_reason' => null,
            'head'         => $head,
            'root_id'      => $rootId,
            'structure'    => $structure,
            'categories'   => $categories,
            'fishbone'     => $this->fishboneLayout($categories, $head),
            'nodes'        => array_values($nodes),
            'edges'        => $edges,
            'inference'    => $analysis === null ? null : [
                'tier'         => $analysis['tier'],
                'confidence'   => $analysis['confidence'],
                'links'        => $analysis['links'],
                'alternatives' => $analysis['alternatives'],
                'root_label'   => $analysis['root_label'],
                'explanation'  => $analysis['explanation'],
                'timing'       => $analysis['timing'] ?? null,
            ],
        ];
    }

    /**
     * Retail cause categories — the "bones" of the fishbone. Rule → category is a
     * fixed, auditable mapping (no AI). A rule not listed falls into "other".
     */
    private const CATEGORIES = [
        'supply'    => ['label' => 'Supply',        'rules' => ['supplier_fill_rate', 'po_late_receipt', 'po_overdue', 'supplier_lead_time_drift', 'receiving_discrepancy']],
        'demand'    => ['label' => 'Demand',        'rules' => ['sales_drop', 'sales_spike', 'demand_forecast_break', 'demand_seasonality_breach', 'demand_erosion', 'return_rate_spike']],
        'inventory' => ['label' => 'Inventory',     'rules' => ['stockout_risk', 'safety_stock_breach', 'dead_stock', 'phantom_inventory', 'negative_inventory', 'overstock', 'inventory_shrinkage', 'cumulative_shrink', 'multi_location_imbalance', 'reorder_point_staleness']],
        'price'     => ['label' => 'Price & Margin', 'rules' => ['price_anomaly', 'margin_erosion', 'discount_signal', 'cost_spike', 'revenue_concentration_risk', 'slow_moving_capital']],
        'data'      => ['label' => 'Data & Store',  'rules' => ['sku_master_drift', 'duplicate_transaction_ids', 'import_frequency_gap', 'location_proliferation', 'channel_mix_shift', 'store_outlier']],
        'other'     => ['label' => 'Other',         'rules' => []],
    ];

    private function categoryOf(string $rule): string
    {
        foreach (self::CATEGORIES as $key => $def) {
            if (in_array($rule, $def['rules'], true)) {
                return $key;
            }
        }

        return 'other';
    }

    /** Per-signal detail for the click-through drawer — all from governed rows. */
    private function nodeDetail(\App\Models\Anomaly $a, $evidence, string $currency): array
    {
        $evList = [];
        foreach (($evidence ?? collect())->take(6) as $e) {
            $evList[] = [
                'label'     => $e->label,
                'value'     => $e->getFormattedValue(),
                'direction' => $e->direction,
            ];
        }

        $impact = $a->estimatedImpact();

        return [
            'confidence'       => $a->ai_confidence ?: 'unknown',
            'confidence_label' => $a->getConfidenceLabel(),
            'gate_label'       => $a->ai_recommendation_gate ? $a->getGateLabel() : null,
            'impact'           => $impact !== null ? $currency . number_format($impact, 0) : null,
            'lifecycle'        => $a->lifecycle_state,
            'severity'         => $a->severity ?: 'low',
            'sku'              => $a->sku,
            'store_id'         => $a->store_id,
            'description'      => $a->description,
            'evidence'         => $evList,
        ];
    }

    /** The effect at the fish head — the downstream symptom the causes lead to. */
    private function causeHead(?array $analysis, array $nodes, string $structure): array
    {
        if ($analysis !== null && ! empty($analysis['chain']) && count($analysis['chain']) >= 2) {
            $last = end($analysis['chain']); // effect end of the causal chain
            return ['label' => $last['label'], 'sku' => $last['sku'] ?? null, 'kind' => 'effect'];
        }
        if ($structure === 'single' && count($nodes) === 1) {
            $only = reset($nodes);
            return ['label' => $only['label'], 'sku' => $only['sku'], 'kind' => 'single'];
        }

        return ['label' => 'Co-occurring signals', 'sku' => null, 'kind' => 'correlated'];
    }

    /**
     * Ishikawa (fishbone) geometry in a fixed 1000×420 coordinate space; the SVG
     * scales to the container. Spine runs left→head; each category is a diagonal
     * bone (alternating above/below), with its signals as clickable nodes spaced
     * along the bone. Pure layout math — deterministic, no data invented.
     */
    private function fishboneLayout(array $categories, array $head): array
    {
        $w = 1000;
        $headW = 216;
        $headX = $w - $headW;   // spine meets the head here
        $cy = 210;
        $h = 420;
        $ribDX = 190;           // bone horizontal run (toward the tail)
        $ribH = 150;            // bone vertical rise
        $spineLeft = 36;
        $x0min = 210;
        $x0max = $headX - 60;
        $c = count($categories);

        $bones = [];
        $signals = [];
        foreach (array_values($categories) as $j => $cat) {
            $x0 = $c <= 1 ? ($x0min + $x0max) / 2 : $x0min + $j * (($x0max - $x0min) / ($c - 1));
            $top = $cat['side'] === 'top';
            $tipX = $x0 - $ribDX;
            $tipY = $top ? $cy - $ribH : $cy + $ribH;

            $bones[] = [
                'x0'      => round($x0), 'y0' => $cy,
                'x1'      => round($tipX), 'y1' => $tipY,
                'label'   => $cat['label'],
                'label_x' => round($tipX - 2),
                'label_y' => $top ? $tipY - 9 : $tipY + 18,
                'side'    => $cat['side'],
            ];

            $signalsList = array_values($cat['signals']);
            $n = count($signalsList);
            foreach ($signalsList as $k => $s) {
                $t = ($k + 1) / ($n + 1);
                $px = $x0 + $t * ($tipX - $x0);
                $py = $cy + $t * ($tipY - $cy);
                $signals[] = [
                    'id'       => $s['id'],
                    'cx'       => round($px), 'cy' => round($py),
                    'label'    => $this->truncate($s['label'], 22),
                    'label_x'  => round($px - 12),
                    'label_y'  => round($py + 3),
                    'is_root'  => $s['is_root'],
                    'severity' => $s['severity'],
                ];
            }
        }

        return [
            'w' => $w, 'h' => $h, 'cy' => $cy, 'head_x' => $headX,
            // Left/top/bottom gutters so bone-tip and signal labels never clip.
            'viewbox' => '-150 -14 ' . ($w + 190) . ' ' . ($h + 44),
            'head'   => $head,
            'spine'  => ['x1' => $spineLeft, 'x2' => $headX, 'y' => $cy],
            'bones'  => $bones,
            'signals' => $signals,
        ];
    }

    private function truncate(string $s, int $n): string
    {
        return mb_strlen($s) <= $n ? $s : rtrim(mb_substr($s, 0, $n - 1)) . '…';
    }

    /** Group signals into fishbone ribs, alternating above/below the spine. */
    private function fishboneCategories(array $nodesById): array
    {
        $buckets = [];
        foreach ($nodesById as $n) {
            $buckets[$n['category']][] = [
                'id'       => $n['id'],
                'label'    => $n['label'],
                'sku'      => $n['sku'],
                'severity' => $n['severity'],
                'is_root'  => $n['is_root'],
            ];
        }

        $out = [];
        $i = 0;
        foreach (array_keys(self::CATEGORIES) as $key) {
            if (empty($buckets[$key])) {
                continue;
            }
            $out[] = [
                'key'     => $key,
                'label'   => self::CATEGORIES[$key]['label'],
                'side'    => $i % 2 === 0 ? 'top' : 'bottom',
                'signals' => $buckets[$key],
            ];
            $i++;
        }

        return $out;
    }

    // =========================================================================
    // MODULE — WHAT CHANGED (before/after from governed evidence)
    // =========================================================================

    /**
     * The movement behind the signals: the time-series and baseline/threshold
     * evidence already collected, surfaced as a before→after view. Extracts from
     * InvestigationEvidence only — computes no new statistics.
     */
    private function whatChanged(Investigation $investigation): array
    {
        $evidence = $investigation->evidence;
        if ($evidence->isEmpty()) {
            return $this->unavailable('No before/after evidence has been captured yet. It is collected when the investigation is opened or narrated.');
        }

        // The clearest "what changed": a daily numeric series (e.g. daily sales).
        $series = [];
        $seriesLabel = null;
        $seriesUnit = null;
        foreach ($evidence as $e) {
            if ($e->evidence_type === InvestigationEvidence::TYPE_DATA_POINT && is_array($e->value_json)) {
                $numeric = array_filter($e->value_json, 'is_numeric');
                if (count($numeric) >= 3) {
                    foreach ($e->value_json as $k => $v) {
                        if (is_numeric($v)) {
                            $series[] = ['label' => $this->shortDay((string) $k), 'value' => (float) $v];
                        }
                    }
                    $seriesLabel = $e->label;
                    $seriesUnit = $e->unit;
                    break;
                }
            }
        }

        // Baseline reference line for the series, if a baseline mean is on file.
        $baseline = null;
        foreach ($evidence as $e) {
            if ($e->value_numeric !== null && stripos((string) $e->label, 'baseline mean') !== false) {
                $baseline = (float) $e->value_numeric;
                break;
            }
        }

        // Change markers: the calculation / threshold / stat evidence that
        // quantifies the movement (z-score, days of cover, margin, totals,
        // baselines, return rate). Governed scalar values only.
        $markers = [];
        foreach ($evidence as $e) {
            if (! in_array($e->evidence_type, [
                InvestigationEvidence::TYPE_CALCULATION,
                InvestigationEvidence::TYPE_THRESHOLD_BREACH,
                InvestigationEvidence::TYPE_STAT,
            ], true)) {
                continue;
            }
            $value = $e->getFormattedValue();
            if ($value === '—') {
                continue; // JSON-only evidence with no scalar to show as a marker
            }
            $markers[] = [
                'label'     => $e->label,
                'value'     => $value,
                'direction' => $e->direction,
            ];
        }
        // Show the evidence that moved (supports / contradicts) before neutral context.
        usort($markers, fn ($a, $b) => (int) ($a['direction'] === 'neutral') <=> (int) ($b['direction'] === 'neutral'));

        $available = ! empty($series) || ! empty($markers);

        return [
            'available'    => $available,
            'empty_reason' => $available ? null : 'The evidence on file does not yet describe a before/after movement.',
            'series'       => $series,
            'series_label' => $seriesLabel,
            'series_unit'  => $seriesUnit,
            'series_max'   => empty($series) ? 0 : max(array_column($series, 'value')),
            'baseline'     => $baseline,
            'markers'      => array_slice($markers, 0, 8),
        ];
    }

    // =========================================================================
    // MODULE — WHY THIS RECOMMENDATION (traces the recommendation's inputs)
    // =========================================================================

    /**
     * For each prescriptive recommendation, the inputs that produced it: the
     * derived target level, the recommended quantity, the supplier/route and lead
     * time — from the recommendation payload and the governed replenishment
     * target. Autnyx recommends only; it never executes.
     */
    private function whyRecommendation(Investigation $investigation): array
    {
        try {
            $recs = $this->recommendations->forInvestigation($investigation);
        } catch (\Throwable) {
            $recs = [];
        }

        // No live replenishment recommendation? Fall back to the actions already
        // recorded on this investigation — real, governed, and often what the team
        // adopted. Only when there is neither do we say there is nothing.
        if (empty($recs)) {
            $actions = $investigation->actions ?? collect();
            if ($actions->isNotEmpty()) {
                return [
                    'available'    => true,
                    'empty_reason' => null,
                    'source'       => 'actions',
                    'items'        => $actions->map(fn ($act) => [
                        'title'      => $act->title,
                        'kind'       => $act->action_type ?? 'action',
                        'qty'        => null,
                        'value'      => null,
                        'priority'   => $act->priority ?? null,
                        'rationale'  => $act->description,
                        'derivation' => null,
                    ])->values()->all(),
                    'note'         => 'These are the actions recorded on this investigation. Autnyx recommends only — nothing is transmitted; the team carries out each action in their own systems.',
                ];
            }

            return $this->unavailable('No prescriptive recommendation applies here, and no action has been recorded yet. Recommendations are generated for stockout / safety-stock signals that have a derived replenishment target.');
        }

        $currency = $this->currencySymbol($investigation);
        $anomalies = $investigation->anomalies->keyBy('id');

        $items = [];
        foreach ($recs as $r) {
            $anomaly = isset($r['anomaly_id']) ? $anomalies->get($r['anomaly_id']) : null;
            $derivation = null;
            if ($anomaly && $anomaly->sku !== null) {
                $rep = \App\Models\SkuReplenishment::where('tenant_id', $investigation->tenant_id)
                    ->where('sku', $anomaly->sku)
                    ->when($anomaly->store_id, fn ($q) => $q->where('store_id', $anomaly->store_id))
                    ->first();
                if ($rep) {
                    $derivation = [
                        'target'         => $rep->order_up_to !== null ? (int) round((float) $rep->order_up_to) : null,
                        'reorder_point'  => $rep->reorder_point !== null ? (int) round((float) $rep->reorder_point) : null,
                        'lead_time_days' => ($rep->lead_time_days !== null && (float) $rep->lead_time_days > 0) ? (int) round((float) $rep->lead_time_days) : null,
                        'supplier'       => $rep->supplier,
                    ];
                }
            }

            $items[] = [
                'title'      => $r['title'] ?? ($r['label'] ?? 'Recommended action'),
                'kind'       => $r['kind'] ?? 'action',
                'qty'        => isset($r['qty']) ? (int) round((float) $r['qty']) : null,
                'value'      => isset($r['value']) ? $currency . number_format((float) $r['value'], 0) : null,
                'priority'   => $r['priority'] ?? null,
                'rationale'  => $r['description'] ?? null,
                'derivation' => $derivation,
            ];
        }

        return [
            'available'    => true,
            'empty_reason' => null,
            'items'        => $items,
            'note'         => 'Autnyx recommends only — carry the action out in your own ERP/WMS. Adopting one logs a tracked action; nothing is transmitted.',
        ];
    }

    private function shortDay(string $day): string
    {
        try {
            return \Illuminate\Support\Carbon::parse($day)->format('M j');
        } catch (\Throwable) {
            return mb_substr($day, 0, 10);
        }
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
        // Track A — causal inference (correlated | likely | corroborated).
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

        // WP1.1: the AI's own guess lives in ai_revenue_estimate (labelled);
        // revenue_at_risk is deterministic and already equals $totalAtRisk.
        $aiEstimate = $investigation->ai_revenue_estimate !== null
            ? (float) $investigation->ai_revenue_estimate
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
