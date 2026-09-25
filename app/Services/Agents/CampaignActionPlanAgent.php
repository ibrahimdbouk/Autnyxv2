<?php

namespace App\Services\Agents;

use App\Filament\Pages\ActionQueue;
use App\Models\Action;
use App\Models\Anomaly;
use App\Models\AgentRun;
use App\Models\Investigation;
use App\Models\Store;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Campaign Action-Plan Agent (Agent #1).
 *
 * Sits on the Action Queue. For one campaign (e.g. "Availability risk",
 * "Demand erosion") it turns the deterministic aggregate into a workable plan a
 * category manager can act on in one pass.
 *
 * Two clean phases, split by a human:
 *   1. PROPOSE  — read the campaign's aggregate, ask the reasoning model for a
 *      structured plan (objective, ordered steps, priority focus, expected
 *      outcome). Stored as an AgentRun (status = proposed). Nothing changes.
 *   2. EXECUTE  — only after a person clicks Accept. Execution is DETERMINISTIC
 *      and stays inside Autnyx: it creates one remediation Action per top
 *      investigation in the campaign, advances those investigations to
 *      in_progress, and assembles an exportable PO draft. It never writes to a
 *      customer ERP, and it never actions a SKU the AI merely named — the
 *      engine's own anomalies drive what is touched; the AI only supplies the
 *      narrative and the recommended approach.
 */
class CampaignActionPlanAgent extends AgentService
{
    /** How many investigations (ranked by value) a single execution will action. */
    private const EXECUTE_CAP = 30;

    /** Campaign → the remediation Action type its accepted plan creates. */
    private const CAMPAIGN_ACTION_TYPE = [
        'Demand erosion'          => Action::TYPE_PRICE_ADJUSTMENT,
        'Seasonal shift'          => Action::TYPE_REORDER,
        'Idle & non-moving stock' => Action::TYPE_TRANSFER,
        'Availability risk'       => Action::TYPE_REORDER,
        'Supply issues'           => Action::TYPE_SUPPLIER_CONTACT,
        'Demand shifts'           => Action::TYPE_INVESTIGATE_FURTHER,
        'Margin & pricing'        => Action::TYPE_PRICE_ADJUSTMENT,
        'Inventory integrity'     => Action::TYPE_INVESTIGATE_FURTHER,
        'Data quality'            => Action::TYPE_OTHER,
    ];

    /** Campaigns whose accepted plan yields a purchase-order draft. */
    private const PO_CAMPAIGNS = ['Availability risk', 'Seasonal shift'];

    protected function agentKey(): string
    {
        return AgentRun::KEY_CAMPAIGN_PLAN;
    }

    // =========================================================================
    // PHASE 1 — PROPOSE
    // =========================================================================

    /**
     * Draft (or re-draft) the action plan for a campaign. Supersedes any earlier
     * still-open proposal for the same campaign so the page shows one current plan.
     */
    public function propose(int $tenantId, string $campaignName, ?int $requestedBy = null): AgentRun
    {
        $this->callTenant = $tenantId;
        $agg = $this->aggregate($tenantId, $campaignName);
        $openPlans = fn ($q) => $q->where('tenant_id', $tenantId)->where('subject_type', 'campaign')
            ->where('subject_id', $campaignName)->whereIn('status', [AgentRun::STATUS_PROPOSED, AgentRun::STATUS_ACCEPTED]);

        if (($agg['case_count'] ?? 0) === 0) {
            // Nothing open: earlier plans are moot.
            $openPlans(AgentRun::query()->where('agent_key', $this->agentKey()))->update(['status' => AgentRun::STATUS_DISMISSED]);

            return $this->record([
                'tenant_id'    => $tenantId,
                'subject_type' => 'campaign',
                'subject_id'   => $campaignName,
                'title'        => $campaignName,
                'status'       => AgentRun::STATUS_DISMISSED,
                'input'        => $agg,
                'output'       => ['objective' => 'Nothing open in this campaign right now.'],
                'confidence'   => 'unknown',
                'requested_by' => $requestedBy,
            ]);
        }

        $result = $this->callClaude(
            $this->buildPrompt($agg),
            $this->reasoningModel(),
            1500
        );

        if (! $result['ok'] || empty($result['data'])) {
            return $this->record([
                'tenant_id'    => $tenantId,
                'subject_type' => 'campaign',
                'subject_id'   => $campaignName,
                'title'        => $campaignName,
                'status'       => AgentRun::STATUS_FAILED,
                'input'        => $agg,
                'error'        => $result['error'] ?? 'empty_response',
                'model'        => $this->reasoningModel(),
                'requested_by' => $requestedBy,
            ]);
        }

        // WP5.4: freeze WHAT the plan covers at proposal time — the investigations
        // a person saw when accepting it — so execution can't drift onto new ones.
        $agg['target_investigation_ids'] = array_keys($this->rankTargets($tenantId, ActionQueue::rulesForCampaign($campaignName)));

        $d = $result['data'];
        $output = [
            'objective'        => (string) ($d['objective'] ?? ''),
            'summary'          => (string) ($d['summary'] ?? ''),
            'steps'            => $this->toList($d['steps'] ?? null),
            'priority_focus'   => (string) ($d['priority_focus'] ?? ''),
            'expected_outcome' => (string) ($d['expected_outcome'] ?? ''),
            'watchouts'        => $this->toList($d['watchouts'] ?? null),
        ];

        // One live plan at a time — earlier ones are retired only now it succeeded (WP5.4).
        return $this->recordReplacing([
            'tenant_id'     => $tenantId,
            'subject_type'  => 'campaign',
            'subject_id'    => $campaignName,
            'title'         => $campaignName,
            'status'        => AgentRun::STATUS_PROPOSED,
            'input'         => $agg,
            'output'        => $output,
            'model'         => $this->reasoningModel(),
            'confidence'    => $this->confidence($d['confidence'] ?? null),
            'tokens_input'  => $result['tokens_input'],
            'tokens_output' => $result['tokens_output'],
            'requested_by'  => $requestedBy,
        ], $openPlans);
    }

    // =========================================================================
    // PHASE 2 — EXECUTE (human-accepted; deterministic; internal only)
    // =========================================================================

    /**
     * Carry out Autnyx's side of an accepted plan. Deterministic: it acts on the
     * campaign's real anomalies, not on anything the model invented.
     *
     *   • creates one remediation Action per top investigation (capped),
     *   • advances each of those investigations open → in_progress,
     *   • assembles a downloadable PO draft for replenishment campaigns,
     *   • records everything on the run and in the investigation audit log.
     *
     * Nothing here reaches a customer ERP.
     */
    public function execute(AgentRun $run, ?int $userId = null): AgentRun
    {
        // WP5.4: claim the run under a row lock — two clicks (or two people)
        // can't execute the same plan twice.
        $claimed = DB::transaction(function () use ($run, $userId) {
            $locked = AgentRun::whereKey($run->id)->lockForUpdate()->first();
            if (! $locked || ! in_array($locked->status, [AgentRun::STATUS_PROPOSED, AgentRun::STATUS_ACCEPTED], true)) {
                return false; // executed already, or no longer an open proposal
            }
            // Mark accepted first, so a mid-way failure still records the human decision.
            $locked->update(['status' => AgentRun::STATUS_ACCEPTED, 'acted_by' => $userId, 'acted_at' => now()]);

            return true;
        });
        if (! $claimed) {
            return $run->fresh();
        }
        $run->refresh();

        $tenantId     = (int) $run->tenant_id;
        $campaignName = (string) $run->subject_id;
        $actionType   = self::CAMPAIGN_ACTION_TYPE[$campaignName] ?? Action::TYPE_INVESTIGATE_FURTHER;
        $rules        = ActionQueue::rulesForCampaign($campaignName);

        $byInv  = $this->rankTargets($tenantId, $rules, cap: false);
        $frozen = $run->input['target_investigation_ids'] ?? null;
        $targetInvs = is_array($frozen)
            // Only what the accepted plan covered — and only if still live.
            ? array_intersect_key($byInv, array_flip($frozen))
            : array_slice($byInv, 0, self::EXECUTE_CAP, true);

        $investigations = Investigation::whereIn('id', array_keys($targetInvs))->get()->keyBy('id');
        $skuNames       = Product::where('tenant_id', $tenantId)->pluck('name', 'sku');
        $storeNames     = Store::where('tenant_id', $tenantId)->pluck('name', 'id');
        $currency       = Tenant::find($tenantId)?->currencyCode() ?? 'AED';

        $created   = 0;
        $advanced  = 0;
        $poLines   = [];
        $wantsPo   = in_array($campaignName, self::PO_CAMPAIGNS, true);
        $objective = (string) $run->out('objective', $campaignName);

        DB::transaction(function () use (
            $targetInvs, $investigations, $actionType, $campaignName, $objective,
            $userId, $skuNames, $storeNames, $wantsPo, &$created, &$advanced, &$poLines
        ) {
            foreach ($targetInvs as $invId => $bucket) {
                $inv = $investigations->get($invId);
                if (! $inv) {
                    continue;
                }

                // 1) create the remediation Action (skip if this plan already made one)
                $exists = Action::where('investigation_id', $invId)
                    ->where('action_type', $actionType)
                    ->whereIn('status', [Action::STATUS_UNASSIGNED, Action::STATUS_ASSIGNED, Action::STATUS_ACKNOWLEDGED, Action::STATUS_IN_PROGRESS])
                    ->exists();

                if (! $exists) {
                    $action = Action::create([
                        'investigation_id' => $invId,
                        'action_type'      => $actionType,
                        'title'            => $this->actionTitle($campaignName, $inv, $skuNames),
                        // WP5.4: AI text is labelled as such wherever it lands.
                        'description'      => $objective !== ''
                            ? "AI-drafted plan (accepted by a person): {$objective}"
                            : "From accepted {$campaignName} plan.",
                        'status'           => $inv->assigned_team_id ? Action::STATUS_ASSIGNED : Action::STATUS_UNASSIGNED,
                        'priority'         => $inv->priority ?? Action::PRIORITY_MEDIUM,
                        'assigned_team_id' => $inv->assigned_team_id,
                        'created_by'       => $userId,
                        'due_at'           => now()->addDays(7),
                    ]);
                    $created++;
                    AuditLogger::actionCreated($inv, $action->id, $action->title, $userId);
                }

                // 2) advance the investigation into work
                if ($inv->status === Investigation::STATUS_OPEN) {
                    $old = $inv->status;
                    $inv->update(['status' => Investigation::STATUS_IN_PROGRESS]);
                    AuditLogger::statusChanged($inv, $old, Investigation::STATUS_IN_PROGRESS, $userId);
                    $advanced++;
                }

                // 3) collect PO-draft lines for replenishment campaigns
                if ($wantsPo) {
                    foreach ($bucket['anoms'] as $a) {
                        $poLines[] = [
                            'sku'        => $a->sku,
                            'sku_name'   => $a->sku ? ($skuNames[$a->sku] ?? $a->sku) : '—',
                            'store'      => $a->store_id ? ($storeNames[$a->store_id] ?? ('Store ' . $a->store_id)) : 'chain-wide',
                            'suggested_qty' => $a->context['suggested_order_qty']
                                ?? $a->context['reorder_qty']
                                ?? null,
                            'value_at_risk' => round((float) ($a->context['revenue_impact'] ?? 0), 2),
                        ];
                    }
                }
            }
        });

        // Dedupe + rank PO lines by value; cap for a clean draft.
        if ($wantsPo && $poLines) {
            $seen = [];
            $dedup = [];
            usort($poLines, fn ($x, $y) => $y['value_at_risk'] <=> $x['value_at_risk']);
            foreach ($poLines as $l) {
                $k = ($l['sku'] ?? '') . '|' . ($l['store'] ?? '');
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $dedup[] = $l;
                if (count($dedup) >= 200) {
                    break;
                }
            }
            $poLines = $dedup;
        }

        $output = $run->output ?? [];
        $output['execution'] = [
            'actions_created'        => $created,
            'investigations_advanced'=> $advanced,
            'action_type'            => $actionType,
            'capped_at'              => self::EXECUTE_CAP,
            'targeted'               => count($targetInvs),
            'po_line_count'          => count($poLines),
            'executed_at'            => now()->toIso8601String(),
        ];
        if ($wantsPo) {
            $output['po_draft'] = [
                'currency' => $currency,
                'lines'    => $poLines,
            ];
        }

        $run->update([
            'status'      => AgentRun::STATUS_EXECUTED,
            'output'      => $output,
            'acted_by'    => $userId ?? $run->acted_by,
            'executed_at' => now(),
        ]);

        Log::info("[agent:campaign_plan] executed run #{$run->id} campaign='{$campaignName}' actions={$created} advanced={$advanced}");

        return $run->fresh();
    }

    /**
     * The campaign's live anomalies grouped by investigation, ranked by value,
     * capped at EXECUTE_CAP.
     *
     * @return array<int,array{value:float,skus:array,anoms:array}>
     */
    private function rankTargets(int $tenantId, array $rules, bool $cap = true): array
    {
        $byInv = [];
        Anomaly::where('tenant_id', $tenantId)->active()
            ->whereIn('rule_type', $rules)
            ->whereNotNull('investigation_id')
            ->get(['id', 'investigation_id', 'rule_type', 'sku', 'store_id', 'severity', 'context'])
            ->each(function ($a) use (&$byInv) {
                $id = $a->investigation_id;
                $byInv[$id]['value'] = ($byInv[$id]['value'] ?? 0.0) + (float) ($a->context['revenue_impact'] ?? 0);
                $byInv[$id]['skus'][$a->sku ?? '—'] = true;
                $byInv[$id]['anoms'][] = $a;
            });
        uasort($byInv, fn ($x, $y) => $y['value'] <=> $x['value']);

        return $cap ? array_slice($byInv, 0, self::EXECUTE_CAP, true) : $byInv;
    }

    /** Human-declined a proposal. */
    public function dismiss(AgentRun $run): AgentRun
    {
        if (in_array($run->status, [AgentRun::STATUS_PROPOSED, AgentRun::STATUS_ACCEPTED], true)) {
            $run->update(['status' => AgentRun::STATUS_DISMISSED]);
        }

        return $run->fresh();
    }

    // =========================================================================
    // INPUTS
    // =========================================================================

    /**
     * Deterministic aggregate for one campaign — the exact facts handed to the
     * model, and stored verbatim on the run's `input`.
     *
     * @return array<string,mixed>
     */
    private function aggregate(int $tenantId, string $campaignName): array
    {
        $rules    = ActionQueue::rulesForCampaign($campaignName);
        $currency = Tenant::find($tenantId)?->currencyCode() ?? 'AED';

        $anoms = Anomaly::where('tenant_id', $tenantId)->active()
            ->whereIn('rule_type', $rules)
            ->get(['id', 'investigation_id', 'rule_type', 'sku', 'store_id', 'severity', 'context']);

        $skuNames   = Product::where('tenant_id', $tenantId)->pluck('name', 'sku');
        $storeNames = Store::where('tenant_id', $tenantId)->pluck('name', 'id');

        $skus  = [];
        $invs  = [];
        $high  = 0;
        $total = 0.0;
        $items = [];
        foreach ($anoms as $a) {
            $val = (float) ($a->context['revenue_impact'] ?? 0);
            $total += $val;
            if ($a->sku) {
                $skus[$a->sku] = true;
            }
            if ($a->investigation_id) {
                $invs[$a->investigation_id] = true;
            }
            if ($a->severity === Anomaly::SEVERITY_HIGH) {
                $high++;
            }
            $items[] = [
                'sku_name' => $a->sku ? ($skuNames[$a->sku] ?? $a->sku) : '—',
                'store'    => $a->store_id ? ($storeNames[$a->store_id] ?? ('Store ' . $a->store_id)) : 'chain-wide',
                'severity' => $a->severity,
                'value'    => $val,
            ];
        }

        usort($items, fn ($x, $y) => $y['value'] <=> $x['value']);
        $top = array_slice($items, 0, 8);

        $meta = ActionQueue::campaignMeta($campaignName);

        return [
            'campaign'    => $campaignName,
            'kind'        => $meta['kind'] ?? 'incident',
            'action_hint' => $meta['action'] ?? 'Review',
            'sku_count'   => count($skus),
            'case_count'  => count($invs),
            'high_count'  => $high,
            'total_value' => round($total, 2),
            'total_value_fmt' => Money::compact($total, $currency),
            'currency'    => $currency,
            'top_items'   => array_map(fn ($i) => [
                'sku'      => $i['sku_name'],
                'store'    => $i['store'],
                'severity' => $i['severity'],
                'value_fmt'=> Money::compact($i['value'], $currency),
            ], $top),
        ];
    }

    private function buildPrompt(array $agg): string
    {
        $currency = $agg['currency'];
        $lines = '';
        foreach ($agg['top_items'] as $i) {
            $lines .= "  - {$i['sku']} @ {$i['store']} [{$i['severity']}]: {$i['value_fmt']} at risk\n";
        }
        $kind = $agg['kind'] === 'trend'
            ? 'a portfolio TREND cluster reviewed in bulk'
            : 'a set of discrete operational incidents';

        return <<<PROMPT
You are a retail category-operations lead writing a short, practical action plan for a busy category or store manager. They will read this on the Action Queue and, if they accept it, Autnyx will create the follow-up tasks for them. Be concrete and honest about size — never inflate.

CAMPAIGN: {$agg['campaign']} ({$kind})
SUGGESTED APPROACH: {$agg['action_hint']}
SCALE: {$agg['sku_count']} SKUs across {$agg['case_count']} open cases; {$agg['high_count']} high-severity.
TOTAL VALUE AT RISK: {$agg['total_value_fmt']} (currency {$currency})

TOP ITEMS BY VALUE:
{$lines}

WRITING RULES:
1. Use the product and store NAMES above; never invent SKUs or codes.
2. Money in {$currency}, sensibly rounded. No dollar sign unless the currency is USD.
3. If the money at stake is modest, say so plainly — do not oversell.
4. Steps must be things a manager actually does this week (review, reprice, transfer, reorder, chase supplier, delist), in order.
5. No blanks, no dashes as values. Leave a field out rather than pad it.

Respond with ONLY this JSON object (no markdown, no code fences):
{
  "objective": "ONE sentence: the goal of working this campaign.",
  "summary": "2-3 sentences: what this cluster is, why it matters, honest about its size.",
  "confidence": "one of: established | probable | suspected | unknown",
  "steps": ["3-6 ordered, concrete steps for this week"],
  "priority_focus": "One sentence: which items to hit first and why.",
  "expected_outcome": "One sentence: the {$currency} outcome if the plan is worked.",
  "watchouts": ["0-3 short risks or caveats; omit if none"]
}
PROMPT;
    }

    private function actionTitle(string $campaignName, Investigation $inv, $skuNames): string
    {
        $sku = $inv->primary_sku ? ($skuNames[$inv->primary_sku] ?? $inv->primary_sku) : 'items';

        return match ($campaignName) {
            'Availability risk'       => "Replenish {$sku}",
            'Seasonal shift'          => "Align stock to season — {$sku}",
            'Demand erosion'          => "Review price / promotion — {$sku}",
            'Idle & non-moving stock' => "Rebalance or clear {$sku}",
            'Supply issues'           => "Chase supplier — {$sku}",
            'Margin & pricing'        => "Review margin — {$sku}",
            default                   => "{$campaignName} — {$sku}",
        };
    }
}
