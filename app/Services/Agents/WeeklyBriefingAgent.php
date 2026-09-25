<?php

namespace App\Services\Agents;

use App\Filament\Pages\ActionQueue;
use App\Models\AgentRun;
use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\Tenant;
use App\Support\Money;
use Carbon\Carbon;

/**
 * Weekly Briefing Agent (Agent #2).
 *
 * Produces the Monday "state of the business" note per tenant: what changed in
 * the last week, what's new, what's recovering, where the money moved, and where
 * to look next. It reads only deterministic engine output (investigations,
 * outcomes, active anomalies) and narrates it — it decides nothing.
 *
 * Stored as an AgentRun (subject = tenant, status = complete). Rendered on the
 * Weekly Briefing page and refreshable on demand; a scheduled command generates
 * it every Monday.
 */
class WeeklyBriefingAgent extends AgentService
{
    protected function agentKey(): string
    {
        return AgentRun::KEY_WEEKLY_BRIEFING;
    }

    /**
     * Generate this week's briefing for a tenant. Supersedes the previous one so
     * the page always shows the current week.
     */
    public function generate(int $tenantId, ?int $requestedBy = null): AgentRun
    {
        $this->callTenant = $tenantId;
        $stats = $this->weeklyStats($tenantId);

        $result = $this->callClaude($this->buildPrompt($stats), $this->reasoningModel(), 1500);

        if (! $result['ok'] || empty($result['data'])) {
            return $this->record([
                'tenant_id'    => $tenantId,
                'subject_type' => 'tenant',
                'subject_id'   => (string) $tenantId,
                'title'        => 'Weekly briefing',
                'status'       => AgentRun::STATUS_FAILED,
                'input'        => $stats,
                'error'        => $result['error'] ?? 'empty_response',
                'model'        => $this->reasoningModel(),
                'requested_by' => $requestedBy,
            ]);
        }

        $d = $result['data'];
        $output = [
            'headline'          => (string) ($d['headline'] ?? ''),
            'summary'           => (string) ($d['summary'] ?? ''),
            'whats_new'         => $this->toList($d['whats_new'] ?? null),
            'whats_recovering'  => $this->toList($d['whats_recovering'] ?? null),
            'where_money_moved' => $this->toList($d['where_money_moved'] ?? null),
            'focus_next_week'   => $this->toList($d['focus_next_week'] ?? null),
            'stats'             => $stats,          // keep the deterministic facts alongside
        ];

        return $this->recordReplacing([
            'tenant_id'     => $tenantId,
            'subject_type'  => 'tenant',
            'subject_id'    => (string) $tenantId,
            'title'         => 'Weekly briefing — ' . \App\Support\Tenancy\TenantClock::now($tenantId)->format('d M Y'),
            'status'        => AgentRun::STATUS_COMPLETE,
            'input'         => $stats,
            'output'        => $output,
            'model'         => $this->reasoningModel(),
            'confidence'    => $this->confidence($d['confidence'] ?? 'probable'),
            'tokens_input'  => $result['tokens_input'],
            'tokens_output' => $result['tokens_output'],
            'requested_by'  => $requestedBy,
        ], fn ($q) => $q->where('tenant_id', $tenantId)->where('status', AgentRun::STATUS_COMPLETE)
            // WP5.4: one briefing per tenant per LOCAL day — a regenerate replaces today's.
            ->where('created_at', '>=', \App\Support\Tenancy\TenantClock::today($tenantId)));
    }

    // =========================================================================
    // DETERMINISTIC INPUTS
    // =========================================================================

    /**
     * The week's deterministic movement for a tenant.
     *
     * @return array<string,mixed>
     */
    public function weeklyStats(int $tenantId): array
    {
        $currency = Tenant::find($tenantId)?->currencyCode() ?? 'AED';
        $since    = Carbon::now()->subDays(7);

        // Opened this week.
        $openedQ = Investigation::where('tenant_id', $tenantId)->where('opened_at', '>=', $since);
        $opened      = (clone $openedQ)->count();
        $openedValue = (float) (clone $openedQ)->sum('revenue_at_risk');

        // Resolved / closed this week.
        $resolved = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_RESOLVED, Investigation::STATUS_CLOSED])
            ->where('resolved_at', '>=', $since)
            ->count();

        // Recovery recorded this week.
        $recovery = (float) InvestigationOutcome::where('tenant_id', $tenantId)
            ->where('recorded_at', '>=', $since)
            ->sum('observed_recovery');

        // Standing open picture.
        $openBase = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS]);
        $openNow      = (clone $openBase)->count();
        $critical     = (clone $openBase)->where('priority', Investigation::PRIORITY_CRITICAL)->count();
        $high         = (clone $openBase)->where('priority', Investigation::PRIORITY_HIGH)->count();
        $openValue    = (float) (clone $openBase)->sum('revenue_at_risk');

        // Top campaigns by value at risk, from active anomalies.
        $ruleToCampaign = [];
        foreach (ActionQueue::campaignsMap() as $name => $cfg) {
            foreach ($cfg['rules'] as $rule) {
                $ruleToCampaign[$rule] = $name;
            }
        }
        $campVal = [];
        foreach (Anomaly::where('tenant_id', $tenantId)->active()->get(['rule_type', 'context']) as $a) {
            $name = $ruleToCampaign[$a->rule_type] ?? 'Other signals';
            $campVal[$name] = ($campVal[$name] ?? 0.0) + (float) ($a->context['revenue_impact'] ?? 0);
        }
        arsort($campVal);
        $topCampaigns = [];
        foreach (array_slice($campVal, 0, 4, true) as $name => $val) {
            $topCampaigns[] = ['name' => $name, 'value_fmt' => Money::compact($val, $currency)];
        }

        return [
            'currency'          => $currency,
            'window_days'       => 7,
            'opened'            => $opened,
            'opened_value_fmt'  => Money::compact($openedValue, $currency),
            'resolved'          => $resolved,
            'recovery_fmt'      => Money::compact($recovery, $currency),
            'open_now'          => $openNow,
            'critical'          => $critical,
            'high'              => $high,
            'open_value_fmt'    => Money::compact($openValue, $currency),
            'top_campaigns'     => $topCampaigns,
        ];
    }

    private function buildPrompt(array $s): string
    {
        $campaigns = '';
        foreach ($s['top_campaigns'] as $c) {
            $campaigns .= "  - {$c['name']}: {$c['value_fmt']} at risk\n";
        }
        $campaigns = $campaigns ?: "  (no active campaigns)\n";

        return <<<PROMPT
You are writing the Monday-morning business briefing for a retail category or operations lead. It should read like a sharp colleague's summary they can absorb in under a minute — plain, honest, specific. Do not inflate; if the week was quiet, say so.

CURRENCY: {$s['currency']}
LAST 7 DAYS:
  - New investigations opened: {$s['opened']} ({$s['opened_value_fmt']} value at risk)
  - Investigations resolved/closed: {$s['resolved']}
  - Recovery recorded: {$s['recovery_fmt']}
STANDING PICTURE (open now):
  - Open investigations: {$s['open_now']} ({$s['critical']} critical, {$s['high']} high) — {$s['open_value_fmt']} total value at risk
TOP CAMPAIGNS BY VALUE:
{$campaigns}

WRITING RULES:
1. Money in {$s['currency']}, sensibly rounded. No dollar sign unless the currency is USD.
2. Be honest about size and about a quiet week. Never manufacture drama.
3. Concrete and plain. No jargon, no filler, no blanks or dashes as values.
4. Base every claim on the numbers above — do not invent products, stores, or figures.

Respond with ONLY this JSON object (no markdown, no code fences):
{
  "headline": "ONE sentence capturing the week.",
  "summary": "2-4 plain sentences: the state of the business this week.",
  "whats_new": ["1-3 bullets on what opened or emerged this week"],
  "whats_recovering": ["0-3 bullets on recovery/resolution; omit if nothing moved"],
  "where_money_moved": ["1-3 bullets on where value at risk concentrates or shifted"],
  "focus_next_week": ["2-4 concrete priorities for the coming week"],
  "confidence": "one of: established | probable | suspected | unknown"
}
PROMPT;
    }
}
