<?php

namespace App\Services\Assortment;

use App\Models\AssortmentBriefing;
use App\Models\AssortmentCalibration;
use App\Models\AssortmentGap;
use App\Models\AssortmentPlan;
use App\Models\Tenant;
use App\Services\AI\AnthropicClient;
use App\Support\Money;
use Carbon\Carbon;

/**
 * v2.1 — the weekly assortment briefing. Rules decide; AI only narrates.
 *
 *   the rules   pick and rank what is in it, from the records: new decisions
 *               worth the most, plans waiting, tasks to do and overdue, results
 *               measured, calibrations to review — and write a plain headline
 *               and summary themselves, so the briefing is complete without AI
 *   AI          (when on) rewrites only the headline and summary, from those
 *               facts; a sentence that names a number not in the facts is
 *               dropped and the rules' own words are kept
 *
 * One briefing per tenant per week (the Monday it is built on); built again
 * the same week, it is replaced. Only for a tenant that is live.
 */
class BriefingService
{
    public const TOP = 5;

    public function __construct(private ?AnthropicClient $ai = null) {}

    public function weekOf(?Carbon $now = null): Carbon
    {
        return ($now ?? now())->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /** Build (or rebuild) this week's briefing. Null when the tenant is not live. */
    public function generate(Tenant $tenant, bool $narrate = true, ?Carbon $now = null): ?AssortmentBriefing
    {
        if (! $tenant->hasApp(Tenant::APP_ASSORTMENT) || ! TenantAssortment::isLive($tenant)) {
            return null;
        }
        $content = $this->content($tenant, $now);
        [$narrative, $model] = $narrate ? $this->narrate($tenant, $content) : [null, null];

        return AssortmentBriefing::updateOrCreate(
            ['tenant_id' => $tenant->id, 'week_of' => $this->weekOf($now)->toDateString()],
            ['content' => $content, 'narrative' => $narrative, 'narrated_model' => $model],
        );
    }

    public function latest(Tenant $tenant): ?AssortmentBriefing
    {
        return AssortmentBriefing::where('tenant_id', $tenant->id)->orderByDesc('week_of')->first();
    }

    /** The rules' briefing: facts, sections and words. */
    public function content(Tenant $tenant, ?Carbon $now = null): array
    {
        $now ??= now();
        $since = $now->copy()->subDays(7);
        $cur = $tenant->currencyCode();
        $money = fn ($v) => Money::compact((float) $v, $cur);
        $range = fn ($lo, $hi) => $money($lo) === $money($hi) ? 'about ' . $money($lo) : $money($lo) . '–' . $money($hi);
        $tid = (int) $tenant->id;

        $open = AssortmentGap::where('tenant_id', $tid)->where('status', AssortmentGap::STATUS_OPEN);
        $new = (clone $open)->where('created_at', '>=', $since);
        $newN = (clone $new)->count();
        $newLo = (float) (clone $new)->sum('value_low');
        $newHi = (float) (clone $new)->sum('value_high');
        $openN = (clone $open)->count();
        // Worth deciding now: this week's new decisions first, then the rest, by value × confidence.
        $top = (clone $open)->orderByRaw('CASE WHEN created_at >= ? THEN 0 ELSE 1 END', [$since])
            ->orderByRaw('value_mid * confidence DESC')->orderBy('id')->limit(self::TOP)->get();

        $proposed = AssortmentPlan::where('tenant_id', $tid)->where('status', AssortmentPlan::STATUS_PROPOSED)->where('feasible', true);
        $plansN = TenantAssortment::plansLive($tenant) ? (clone $proposed)->count() : 0;
        $plans = $plansN > 0 ? (clone $proposed)->with('store:id,name')->orderByRaw('value_mid * confidence DESC')->orderBy('id')->limit(3)->get() : collect();

        $tasks = AssortmentGap::where('tenant_id', $tid)->where('status', AssortmentGap::STATUS_ACCEPTED)->where('task_status', AssortmentGap::TASK_TO_DO);
        $tasksN = (clone $tasks)->count();
        $overdueN = (clone $tasks)->whereNotNull('due_at')->where('due_at', '<', $now)->count();
        $resets = AssortmentPlan::where('tenant_id', $tid)->where('status', AssortmentPlan::STATUS_ACCEPTED);
        $resetsN = (clone $resets)->count();
        $resetsOverdue = (clone $resets)->whereNotNull('due_at')->where('due_at', '<', $now)->count();

        $measured = collect()
            ->merge(AssortmentGap::where('tenant_id', $tid)->where('measured_at', '>=', $since)->get(['measurement'])->pluck('measurement'))
            ->merge(AssortmentPlan::where('tenant_id', $tid)->where('measured_at', '>=', $since)->get(['measurement'])->pluck('measurement'));
        $verdicts = $measured->countBy(fn ($m) => $m['verdict'] ?? 'unknown');
        $uplift = (float) $measured->sum(fn ($m) => (float) ($m['uplift_per_year'] ?? 0));
        $cal = AssortmentCalibration::where('tenant_id', $tid)->where('status', AssortmentCalibration::STATUS_PROPOSED)->count();

        $facts = [
            'currency' => $cur, 'since' => $since->toDateString(), 'until' => $now->toDateString(),
            'new_decisions' => $newN, 'new_value' => $newN ? $range($newLo, $newHi) : null, 'open_decisions' => $openN,
            'plans_waiting' => $plansN, 'tasks_to_do' => $tasksN, 'tasks_overdue' => $overdueN,
            'resets_to_do' => $resetsN, 'resets_overdue' => $resetsOverdue,
            'measured' => $measured->count(), 'worked' => (int) ($verdicts['success'] ?? 0), 'partly' => (int) ($verdicts['partial'] ?? 0),
            'did_not' => (int) ($verdicts['failure'] ?? 0), 'measured_value' => $measured->isEmpty() ? null : ($uplift >= 0 ? '+' : '−') . $money(abs($uplift)),
            'calibrations' => $cal,
        ];

        $topRows = $top->map(fn (AssortmentGap $g) => [
            'id' => $g->id, 'type' => $g->type, 'headline' => $g->headline(), 'value' => $g->valueRange($cur),
            'tier' => $g->confidence_tier, 'new' => $g->created_at && $g->created_at->gte($since),
        ])->all();
        $planRows = $plans->map(fn (AssortmentPlan $p) => [
            'id' => $p->id, 'headline' => $p->headline(),
            'value' => isset($p->impact['sales']) ? $this->signedRange($p->impact['sales'], $money) : null,
        ])->all();

        return [
            'facts'    => $facts,
            'top'      => $topRows,
            'plans'    => $planRows,
            'headline' => $this->headline($facts),
            'summary'  => $this->summary($facts),
            'focus'    => $this->focus($facts, $topRows, $planRows),
            'built_at' => $now->toIso8601String(),
        ];
    }

    private function signedRange(array $r, callable $money): string
    {
        $s = fn ($v) => ($v >= 0 ? '+' : '−') . $money(abs((float) $v));
        $lo = min($r[0], $r[2]);
        $hi = max($r[0], $r[2]);

        return $s($lo) === $s($hi) ? $s($r[1]) : $s($lo) . ' to ' . $s($hi);
    }

    private function headline(array $f): string
    {
        $parts = [];
        if ($f['new_decisions'] > 0) {
            $parts[] = $f['new_decisions'] . ' new range decision' . ($f['new_decisions'] === 1 ? '' : 's') . ' worth ' . $f['new_value'] . ' a year';
        }
        if ($f['plans_waiting'] > 0) {
            $parts[] = $f['plans_waiting'] . ' range plan' . ($f['plans_waiting'] === 1 ? '' : 's') . ' to decide';
        }
        if ($f['measured'] > 0) {
            $parts[] = $f['measured'] . ' result' . ($f['measured'] === 1 ? '' : 's') . ' measured';
        }
        if ($parts === []) {
            return $f['open_decisions'] > 0
                ? 'A quiet week: nothing new, ' . $f['open_decisions'] . ' decision' . ($f['open_decisions'] === 1 ? '' : 's') . ' still open'
                : 'A quiet week: nothing new to decide';
        }

        return ucfirst(implode('; ', $parts));
    }

    private function summary(array $f): string
    {
        $s = [];
        $s[] = $f['open_decisions'] . ' range decision' . ($f['open_decisions'] === 1 ? ' is' : 's are') . ' open'
            . ($f['new_decisions'] > 0 ? ', ' . $f['new_decisions'] . ' of them new this week.' : '.');
        $work = $f['tasks_to_do'] + $f['resets_to_do'];
        if ($work > 0) {
            $over = $f['tasks_overdue'] + $f['resets_overdue'];
            $s[] = $work . ' accepted change' . ($work === 1 ? ' is' : 's are') . ' waiting to be made in store' . ($over > 0 ? ', ' . $over . ' overdue.' : '.');
        }
        if ($f['measured'] > 0) {
            $s[] = 'Measured against similar stores this week: ' . $f['worked'] . ' worked, ' . $f['partly'] . ' partly, ' . $f['did_not'] . ' did not (' . $f['measured_value'] . ' a year in total).';
        }
        if ($f['calibrations'] > 0) {
            $s[] = 'A calibration of the rules is waiting for an admin.';
        }

        return implode(' ', $s);
    }

    /** @return array<int,string> what to do this week, in order */
    private function focus(array $f, array $top, array $plans): array
    {
        $out = [];
        if (($f['tasks_overdue'] + $f['resets_overdue']) > 0) {
            $out[] = 'Chase the ' . ($f['tasks_overdue'] + $f['resets_overdue']) . ' overdue change(s): results are only measured once the change is marked done.';
        }
        if ($top !== []) {
            $out[] = 'Decide first: ' . $top[0]['headline'] . ' (' . $top[0]['value'] . ' a year).';
        }
        if ($plans !== []) {
            $out[] = 'Review the range plan for ' . $plans[0]['headline'] . '.';
        }
        if ($f['calibrations'] > 0) {
            $out[] = 'Approve or reject the proposed calibration in Assortment → Learning.';
        }

        return $out;
    }

    // ── AI: words only ────────────────────────────────────────────────────────

    /** @return array{0: ?array, 1: ?string} narrative and model, or nulls */
    public function narrate(Tenant $tenant, array $content): array
    {
        $ai = $this->ai ?? app(AnthropicClient::class);
        $f = $content['facts'];
        $lines = collect($f)->except(['currency', 'since', 'until'])->map(fn ($v, $k) => "  {$k}: " . ($v ?? 'none'))->implode("\n");
        $top = collect($content['top'])->map(fn ($t) => "  - {$t['headline']} ({$t['value']} a year)")->implode("\n") ?: '  (none)';
        $prompt = <<<PROMPT
Write the headline and a short summary of this week's range (assortment) briefing for a retail category manager. The facts below are complete and final: do not add, round differently or invent any number, product, store or claim. If the week was quiet, say so plainly.

<data>
CURRENCY: {$f['currency']}
WEEK: {$f['since']} to {$f['until']}
FACTS:
{$lines}
WORTH DECIDING NOW:
{$top}
</data>

Respond with ONLY this JSON object:
{"headline": "one plain sentence", "summary": "2-3 plain sentences"}
PROMPT;
        $r = $ai->message((int) $tenant->id, 'assortment_briefing', $prompt, 'fast', 400);
        if (! $r->ok) {
            return [null, null];
        }
        $d = $r->json();
        $headline = trim((string) ($d['headline'] ?? ''));
        $summary = trim((string) ($d['summary'] ?? ''));
        if ($headline === '' || ! $this->onlyKnownNumbers($headline . ' ' . $summary, $content)) {
            return [null, null];
        }

        return [['headline' => mb_substr($headline, 0, 240), 'summary' => mb_substr($summary, 0, 800)], $r->model];
    }

    /** Every number in the text must appear in the facts (as a count or a formatted amount). */
    public function onlyKnownNumbers(string $text, array $content): bool
    {
        $known = json_encode([$content['facts'], $content['top'], $content['plans']], JSON_UNESCAPED_UNICODE);
        preg_match_all('/\d[\d.,]*\s?[kKmM]?/u', $text, $m);
        foreach ($m[0] as $n) {
            $n = trim(rtrim(trim($n), '.,'));
            if ($n !== '' && ! str_contains($known, $n) && ! str_contains($known, '"' . (int) $n . '"') && ! preg_match('/[:,]' . preg_quote($n, '/') . '[,}]/', $known)) {
                return false;
            }
        }

        return true;
    }
}
