<?php

namespace App\Services\Agents;

use App\Models\Action;
use App\Models\AgentRun;
use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Money;
use Carbon\Carbon;

/**
 * Action Follow-Up Agent (Agent #6).
 *
 * Closes the loop. For investigations that have been actioned, it reads the
 * deterministic recovery signal — observed recovery, outcome state, whether the
 * SKU is still firing anomalies, how long since the action — and drafts a
 * "did it work?" note, recommending escalation when a fix hasn't taken. It
 * recommends; it never reopens or escalates on its own.
 *
 * One AgentRun per investigation (subject = investigation). Surfaced on the
 * Follow-Ups page; runnable by scheduled command.
 */
class ActionFollowUpAgent extends AgentService
{
    /** Only look at investigations actioned within this window. */
    private const LOOKBACK_DAYS = 45;

    protected function agentKey(): string
    {
        return AgentRun::KEY_ACTION_FOLLOWUP;
    }

    /**
     * Follow up on a tenant's actioned investigations (highest value first).
     *
     * @return array<int,AgentRun>
     */
    public function followForTenant(int $tenantId, int $limit = 20): array
    {
        $since = Carbon::now()->subDays(self::LOOKBACK_DAYS);

        // Candidates: in-progress investigations that have at least one action.
        $candidates = Investigation::where('tenant_id', $tenantId)
            ->where('status', Investigation::STATUS_IN_PROGRESS)
            ->whereHas('actions', fn ($q) => $q->where('created_at', '>=', $since))
            ->orderByDesc('revenue_at_risk')
            ->limit($limit)
            ->get();

        $runs = [];
        foreach ($candidates as $inv) {
            try {
                $runs[] = $this->followInvestigation($inv);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("[agent:action_followup] inv {$inv->id}: {$e->getMessage()}");
            }
        }

        return $runs;
    }

    public function followInvestigation(Investigation $inv, ?int $requestedBy = null): AgentRun
    {
        $this->callTenant = (int) $inv->tenant_id;
        $signal = $this->signalFor($inv);
        // WP5.4: the earlier follow-up is superseded only once this one succeeds.

        $result = $this->callClaude($this->buildPrompt($signal), $this->fastModel(), 700);

        if (! $result['ok'] || empty($result['data'])) {
            return $this->record([
                'tenant_id'    => $inv->tenant_id,
                'subject_type' => 'investigation',
                'subject_id'   => (string) $inv->id,
                'title'        => $inv->title,
                'status'       => AgentRun::STATUS_FAILED,
                'input'        => $signal,
                'error'        => $result['error'] ?? 'empty_response',
                'model'        => $this->fastModel(),
                'requested_by' => $requestedBy,
            ]);
        }

        $d = $result['data'];
        // WP5.4: the status is decided from the signal, never by the model.
        $status = self::followStatus($signal);

        $output = [
            'follow_status'     => $status,
            'note'              => (string) ($d['note'] ?? ''),
            'recommend_escalate'=> $status === 'stalled',
            'next_check_days'   => is_numeric($d['next_check_days'] ?? null) ? (int) $d['next_check_days'] : 7,
            'signal'            => $signal,
        ];

        return $this->recordReplacing([
            'tenant_id'     => $inv->tenant_id,
            'subject_type'  => 'investigation',
            'subject_id'    => (string) $inv->id,
            'title'         => $inv->title,
            'status'        => AgentRun::STATUS_COMPLETE,
            'input'         => $signal,
            'output'        => $output,
            'model'         => $this->fastModel(),
            'confidence'    => $this->confidence($d['confidence'] ?? 'suspected'),
            'tokens_input'  => $result['tokens_input'],
            'tokens_output' => $result['tokens_output'],
            'requested_by'  => $requestedBy,
        ], fn ($q) => $q->where('tenant_id', $inv->tenant_id)->where('subject_type', 'investigation')
            ->where('subject_id', (string) $inv->id)->where('status', AgentRun::STATUS_COMPLETE));
    }

    /**
     * WP5.4 — the follow-up status from the signal:
     *   recovered — recovery observed, or actions done and nothing flagging;
     *   stalled   — an action is 14+ days old and the item still flags with no recovery;
     *   working   — partial recovery / monitoring, or an action under 14 days old;
     *   no_signal — otherwise.
     */
    public static function followStatus(array $s): string
    {
        $state = $s['outcome_state'] ?? null;
        $age   = $s['action_age_days'];
        $flag  = $s['still_flagging'];

        if ($state === 'observed_recovery' || (($s['actions_completed'] ?? 0) > 0 && $flag === 0)) {
            return 'recovered';
        }
        if ($age !== null && $age >= 14 && ($flag ?? 0) > 0 && ! in_array($state, ['partial_recovery', 'observed_recovery'], true)) {
            return 'stalled';
        }
        if (in_array($state, ['partial_recovery', 'monitoring'], true) || ($age !== null && $age < 14)) {
            return 'working';
        }

        return 'no_signal';
    }

    /**
     * The deterministic recovery signal for one investigation.
     *
     * @return array<string,mixed>
     */
    private function signalFor(Investigation $inv): array
    {
        $currency = Tenant::find($inv->tenant_id)?->currencyCode() ?? 'AED';

        $latestAction = Action::where('investigation_id', $inv->id)->latest('created_at')->first();
        $actionAge    = $latestAction?->created_at ? (int) $latestAction->created_at->diffInDays(Carbon::now()) : null;
        $actionCount  = Action::where('investigation_id', $inv->id)->count();
        $completed    = Action::where('investigation_id', $inv->id)->where('status', Action::STATUS_COMPLETED)->count();

        $outcome = InvestigationOutcome::where('investigation_id', $inv->id)->latest('id')->first();

        // Is the SKU still firing anomalies? (problem persists vs cleared)
        $stillFlagging = $inv->primary_sku
            ? Anomaly::where('tenant_id', $inv->tenant_id)->where('sku', $inv->primary_sku)->active()->count()
            : null;

        $productName = $inv->primary_sku
            ? (Product::where('tenant_id', $inv->tenant_id)->where('sku', $inv->primary_sku)->value('name') ?? $inv->primary_sku)
            : 'this item';

        return [
            'investigation_id'  => $inv->id,
            'title'             => $inv->title,
            'product'           => $productName,
            'currency'          => $currency,
            'revenue_at_risk'   => Money::compact((float) $inv->revenue_at_risk, $currency),
            'priority'          => $inv->priority,
            'action_type'       => $latestAction?->action_type,
            'action_status'     => $latestAction?->status,
            'action_age_days'   => $actionAge,
            'action_count'      => $actionCount,
            'actions_completed' => $completed,
            'observed_recovery' => $outcome ? Money::compact((float) $outcome->observed_recovery, $currency) : null,
            'outcome_state'     => $outcome?->outcome_state,
            'still_flagging'    => $stillFlagging,
        ];
    }

    private function buildPrompt(array $s): string
    {
        $status = self::followStatus($s);
        $recovery = $s['observed_recovery'] !== null
            ? "observed recovery {$s['observed_recovery']}, outcome state '{$s['outcome_state']}'"
            : 'no recovery measured yet';
        $flagging = $s['still_flagging'] === null
            ? 'unknown'
            : ($s['still_flagging'] > 0 ? "{$s['still_flagging']} anomalies still active for this item" : 'no anomalies active for this item now');
        $age = $s['action_age_days'] === null ? 'unknown' : "{$s['action_age_days']} days ago";

        return <<<PROMPT
You are following up on a retail investigation that has already been actioned, to judge whether the fix is working. Write one short, honest note for the person who owns it. Base it only on the signal below — do not invent numbers.

INVESTIGATION: {$s['title']}
PRODUCT: {$s['product']}  (priority {$s['priority']}, {$s['revenue_at_risk']} at risk)
ACTION: {$s['action_type']} — status '{$s['action_status']}', taken {$age}; {$s['actions_completed']} of {$s['action_count']} actions completed.
RECOVERY: {$recovery}
CURRENT DETECTION: {$flagging}

STATUS (decided by Autnyx from the signal — explain it, do not change it): {$status}

Respond with ONLY this JSON object (no markdown, no code fences):
{
  "note": "ONE or two plain sentences: what the status means here, and what to do next.",
  "next_check_days": a number of days until the next check,
  "confidence": "one of: established | probable | suspected | unknown"
}
PROMPT;
    }
}
