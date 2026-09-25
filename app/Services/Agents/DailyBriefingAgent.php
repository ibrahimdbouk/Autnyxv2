<?php

namespace App\Services\Agents;

use App\Filament\Pages\ActionQueue;
use App\Models\Action;
use App\Models\AgentRun;
use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\Tenant;
use App\Services\DataQuality\DataReadinessService;
use App\Support\Money;
use Carbon\Carbon;

/**
 * Daily Briefing Agent — the "start of day" note per tenant.
 *
 * The morning sibling of the Weekly Briefing: what landed overnight, what needs a
 * decision today, where the money is at risk right now, and whether any feed is
 * blocked. Like every agent it reads ONLY deterministic engine output
 * (investigations, anomalies, outcomes, actions, the readiness contract) and
 * narrates it — it decides nothing and executes nothing.
 *
 * Stored as an AgentRun (subject = tenant). Rendered on the Daily Briefing page
 * and the dashboard, refreshable on demand; a scheduled command produces it each
 * morning.
 */
class DailyBriefingAgent extends AgentService
{
    protected function agentKey(): string
    {
        return AgentRun::KEY_DAILY_BRIEFING;
    }

    /**
     * Generate today's briefing for a tenant. Supersedes the previous one so the
     * page always shows the current day.
     */
    public function generate(int $tenantId, ?int $requestedBy = null): AgentRun
    {
        $this->callTenant = $tenantId;
        $stats = $this->dailyStats($tenantId);

        $result = $this->callClaude($this->buildPrompt($stats), $this->reasoningModel(), 1200);

        if (! $result['ok'] || empty($result['data'])) {
            return $this->record([
                'tenant_id'    => $tenantId,
                'subject_type' => 'tenant',
                'subject_id'   => (string) $tenantId,
                'title'        => 'Daily briefing',
                'status'       => AgentRun::STATUS_FAILED,
                'input'        => $stats,
                'error'        => $result['error'] ?? 'empty_response',
                'model'        => $this->reasoningModel(),
                'requested_by' => $requestedBy,
            ]);
        }

        $d = $result['data'];
        $output = [
            'headline'       => (string) ($d['headline'] ?? ''),
            'summary'        => (string) ($d['summary'] ?? ''),
            'overnight'      => $this->toList($d['overnight'] ?? null),
            'act_today'      => $this->toList($d['act_today'] ?? null),
            'watch'          => $this->toList($d['watch'] ?? null),
            'stats'          => $stats,
        ];

        return $this->recordReplacing([
            'tenant_id'     => $tenantId,
            'subject_type'  => 'tenant',
            'subject_id'    => (string) $tenantId,
            'title'         => 'Daily briefing — ' . \App\Support\Tenancy\TenantClock::now($tenantId)->format('D, d M Y'),
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
     * Today's deterministic movement for a tenant. Public + side-effect-free so it
     * can be tested directly and reused on the dashboard.
     *
     * @return array<string,mixed>
     */
    public function dailyStats(int $tenantId): array
    {
        $currency  = Tenant::find($tenantId)?->currencyCode() ?? 'AED';
        $dayStart  = Carbon::now()->startOfDay();
        $dayEnd    = Carbon::now()->endOfDay();

        // Landed overnight / today.
        $newSignals = Anomaly::where('tenant_id', $tenantId)
            ->where('detected_at', '>=', $dayStart)
            ->count();

        $openedQ = Investigation::where('tenant_id', $tenantId)->where('opened_at', '>=', $dayStart);
        $openedToday      = (clone $openedQ)->count();
        $openedTodayValue = (float) (clone $openedQ)->sum('revenue_at_risk');

        $resolvedToday = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_RESOLVED, Investigation::STATUS_CLOSED])
            ->where('resolved_at', '>=', $dayStart)
            ->count();

        $recoveryToday = (float) InvestigationOutcome::where('tenant_id', $tenantId)
            ->where('recorded_at', '>=', $dayStart)
            ->sum('observed_recovery');

        // Decisions waiting today — actions due or overdue that are still open.
        $openActions = Action::whereHas('investigation', fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotIn('status', [Action::STATUS_COMPLETED, Action::STATUS_CANCELLED]);
        $dueToday = (clone $openActions)
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$dayStart, $dayEnd])
            ->count();
        $overdue = (clone $openActions)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $dayStart)
            ->count();

        // Blocked feeds right now (readiness contract).
        $blocked = array_keys(app(DataReadinessService::class)->blockedDatasets($tenantId));
        $blockedLabels = array_map(fn ($d) => ucwords(str_replace('_', ' ', $d)), $blocked);

        // Standing open picture.
        $openBase = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS]);
        $openNow   = (clone $openBase)->count();
        $critical  = (clone $openBase)->where('priority', Investigation::PRIORITY_CRITICAL)->count();
        $high      = (clone $openBase)->where('priority', Investigation::PRIORITY_HIGH)->count();
        $openValue = (float) (clone $openBase)->sum('revenue_at_risk');

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
        foreach (array_slice($campVal, 0, 3, true) as $name => $val) {
            $topCampaigns[] = ['name' => $name, 'value_fmt' => Money::compact($val, $currency)];
        }

        return [
            'currency'              => $currency,
            'new_signals'           => $newSignals,
            'opened_today'          => $openedToday,
            'opened_today_value_fmt'=> Money::compact($openedTodayValue, $currency),
            'resolved_today'        => $resolvedToday,
            'recovery_today_fmt'    => Money::compact($recoveryToday, $currency),
            'due_today'             => $dueToday,
            'overdue'               => $overdue,
            'blocked_feeds'         => count($blockedLabels),
            'blocked_feed_labels'   => $blockedLabels,
            'open_now'              => $openNow,
            'critical'              => $critical,
            'high'                  => $high,
            'open_value_fmt'        => Money::compact($openValue, $currency),
            'top_campaigns'         => $topCampaigns,
        ];
    }

    private function buildPrompt(array $s): string
    {
        $campaigns = '';
        foreach ($s['top_campaigns'] as $c) {
            $campaigns .= "  - {$c['name']}: {$c['value_fmt']} at risk\n";
        }
        $campaigns = $campaigns ?: "  (no active campaigns)\n";

        $feeds = empty($s['blocked_feed_labels'])
            ? 'none — all feeds healthy'
            : implode(', ', $s['blocked_feed_labels']) . ' (detection paused for these until a clean batch arrives)';

        return <<<PROMPT
You are writing the start-of-day operations note for a retail category or supply-chain lead. It should read like a sharp colleague's stand-up: what landed overnight, what needs a decision today, and what to keep an eye on. Absorbable in 30 seconds. Be honest — if it was a quiet night, say so plainly.

CURRENCY: {$s['currency']}
SINCE START OF TODAY:
  - New signals detected: {$s['new_signals']}
  - Investigations opened: {$s['opened_today']} ({$s['opened_today_value_fmt']} value at risk)
  - Investigations resolved/closed: {$s['resolved_today']}
  - Recovery recorded: {$s['recovery_today_fmt']}
DECISIONS WAITING:
  - Actions due today: {$s['due_today']}
  - Actions overdue: {$s['overdue']}
DATA FEEDS: {$feeds}
STANDING PICTURE (open now):
  - Open investigations: {$s['open_now']} ({$s['critical']} critical, {$s['high']} high) — {$s['open_value_fmt']} total value at risk
TOP CAMPAIGNS BY VALUE:
{$campaigns}

WRITING RULES:
1. Money in {$s['currency']}, sensibly rounded. No dollar sign unless the currency is USD.
2. Be honest about a quiet day. Never manufacture urgency. If a number is zero, don't dwell on it.
3. Concrete and plain. No jargon, no filler, no blanks or dashes as values.
4. If any feed is blocked, call it out in "act_today" — a blocked feed means detection is running partial.
5. Base every claim on the numbers above — do not invent products, stores, or figures.

Respond with ONLY this JSON object (no markdown, no code fences):
{
  "headline": "ONE sentence capturing the morning.",
  "summary": "2-3 plain sentences: where things stand at the start of the day.",
  "overnight": ["1-3 bullets on what landed since start of day; say 'a quiet night' if little did"],
  "act_today": ["1-4 concrete things to decide or do today (due/overdue actions, blocked feeds, critical signals)"],
  "watch": ["0-3 bullets on what to keep an eye on; omit if nothing stands out"],
  "confidence": "one of: established | probable | suspected | unknown"
}
PROMPT;
    }
}
