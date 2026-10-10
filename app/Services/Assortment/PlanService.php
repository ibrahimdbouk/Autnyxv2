<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGap;
use App\Models\AssortmentPlan;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * v1.5 Phase 2 — what a person does with a range plan, and its review gate.
 *
 *   accept   the whole plan, with any changes unticked: each ticked change's
 *            decisions are accepted (one owner, one due date), and the plan is
 *            ONE reset task for the store — not fifteen
 *   reject   with a reason; the same change set is not proposed again
 *   done     the reset is done: every task of the plan is marked done, and the
 *            plan is measured 8 weeks later on the category's sales
 *
 *   Plans made in the Decision Studio (source studio) are accepted the same
 *   way; their changes picked by a person carry no engine decision, so the
 *   plan is their task.
 *
 *   review   Validation → Plans: the top 20 plans are marked sensible or not;
 *            plans go live (proposed) when at least 70% are sensible and every
 *            sampled plan is reviewed. Decisions must be live first.
 */
class PlanService
{
    public const SAMPLE = 20;

    public function __construct(
        private DecisionService $decisions,
        private AssortmentNotifier $notifier,
        private DecisionLearning $learning,
        private RangeOptimizer $optimiser,
    ) {}

    /**
     * @param  array<int,string>  $untick  change keys the person unticked
     * @param  array<int,int>  $holdouts  v2.1: similar stores held back from this category as the plan's comparison
     */
    public function accept(AssortmentPlan $plan, User $by, ?int $assigneeId = null, ?string $dueAt = null, ?string $note = null, array $untick = [], array $holdouts = []): void
    {
        abort_unless($plan->status === AssortmentPlan::STATUS_PROPOSED && $plan->feasible, 422, 'Only a proposed, feasible plan can be accepted.');
        if (($held = $this->heldBy($plan)) !== null) {
            abort(422, $this->heldMessage($plan, $held));
        }
        $eligible = collect($this->holdoutCandidates($plan, 50))->keyBy('store_id');
        $holdouts = array_values(array_unique(array_map('intval', $holdouts)));
        abort_if(count($holdouts) > self::MAX_HOLDOUTS, 422, 'Hold back at most ' . self::MAX_HOLDOUTS . ' stores.');
        foreach ($holdouts as $id) {
            abort_unless($eligible->has($id), 422, 'A store picked to hold back is not one of the similar stores free to hold back.');
        }
        $holdoutRows = array_map(fn ($id) => ['store_id' => $id, 'name' => $eligible[$id]['name'], 'why' => $eligible[$id]['why']], $holdouts);

        DB::transaction(function () use ($plan, $by, $assigneeId, $dueAt, $note, $untick, $holdoutRows) {
            $changes = $plan->changes ?? [];
            $firstGap = null;
            foreach ($changes as $i => $c) {
                if ($c['kind'] === AssortmentPlan::PROTECT) {
                    continue;
                }
                $ticked = ! in_array($c['key'], $untick, true);
                $changes[$i]['ticked'] = $ticked;
                if (! $ticked) {
                    continue;
                }
                foreach (array_filter([$c['gap_id'] ?? null, $c['out_gap_id'] ?? null]) as $gapId) {
                    $gap = AssortmentGap::where('tenant_id', $plan->tenant_id)->find($gapId);
                    if ($gap && $gap->status === AssortmentGap::STATUS_OPEN) {
                        $this->decisions->accept($gap, $by, $assigneeId, $dueAt, trim('Range plan #' . $plan->id . ($note ? ' · ' . $note : '')), notify: false);
                        $firstGap ??= $gap->fresh();
                    }
                }
            }
            // Stale only when every ticked change rests on an engine decision and none of those is open any more.
            // (A change picked in the Decision Studio has no decision behind it: the plan itself is its task.)
            $ticked = collect($changes)->where('ticked', true)->where('kind', '<>', AssortmentPlan::PROTECT);
            abort_if($firstGap === null && $ticked->isNotEmpty() && $ticked->every(fn ($c) => ! empty($c['gap_id'])), 422,
                'The decisions behind this plan are no longer open — rebuild it with Run now.');

            // With shelf metres: what is left ticked must still fit the shelf (unticking a facing cut can break it).
            $space = $plan->constraints['space'] ?? null;
            if ($space && ($space['basis'] ?? ShelfSpace::BASIS_COUNT) !== ShelfSpace::BASIS_COUNT) {
                $used = (float) ($space['used_before'] ?? 0) + collect($changes)->where('ticked', true)
                    ->where('kind', '<>', AssortmentPlan::PROTECT)->sum(fn ($c) => (float) ($c['metres'] ?? 0));
                abort_if($used > (float) $space['metres'] + 0.0001, 422, sprintf(
                    'With those changes left out the shelf would use %.2f m of its %.2f m — tick the facing cuts that make room, or untick the add they make room for.',
                    $used, (float) $space['metres']));
            }

            $base = $plan->impact['baseline'] ?? ['sales' => 0, 'margin' => 0, 'stock' => 0, 'count' => $plan->current_count];
            $count = $plan->current_count + collect($changes)->where('ticked', true)->sum(fn ($c) => match ($c['kind']) {
                AssortmentPlan::ADD => 1, AssortmentPlan::DELIST => -1, default => 0,
            });
            $plan->forceFill([
                'changes'        => $changes,
                'impact'         => array_merge($this->optimiser->impact($changes, $base, $count, $space),
                    ['margin_known' => $plan->impact['margin_known'] ?? false, 'basis' => $plan->impact['basis'] ?? 'estimated']),
                'proposed_count' => $count,
                'holdouts'       => $holdoutRows === [] ? null : $holdoutRows,
                'status'         => AssortmentPlan::STATUS_ACCEPTED,
                'decided_by'     => $by->id,
                'decided_at'     => now(),
                'decision_note'  => $note ? mb_substr($note, 0, 500) : null,
                'assignee_id'    => $firstGap?->assignee_id ?? $assigneeId,
                'due_at'         => $firstGap?->due_at ?? ($dueAt ? \Illuminate\Support\Carbon::parse($dueAt)->endOfDay() : now()->addDays(14)->endOfDay()),
            ])->save();
        });

        $plan->refresh();
        $this->audit($plan, $by, 'assortment.plan_accepted', 'Accepted range plan: ' . $plan->headline());
        $this->learning->planDecided($plan, true);
        if ($plan->assignee_id && (int) $plan->assignee_id !== (int) $by->id) {
            $this->notifier->planAssigned($plan);
        }
    }

    // ── v2.1 Holdout stores ───────────────────────────────────────────────────

    public const MAX_HOLDOUTS = 3;

    /**
     * Similar stores that can be held back as this plan's comparison: the same
     * peer group, not resetting this category now, not already held back for it.
     * Best match first: category sales and trend over the last 8 weeks closest
     * to the plan's store.
     *
     * @return array<int, array{store_id:int, name:string, why:string, score:float}>
     */
    public function holdoutCandidates(AssortmentPlan $plan, int $limit = 6): array
    {
        $key = $plan->id . ':' . $plan->updated_at?->timestamp;

        return array_slice($this->candidates[$key] ??= $this->rankHoldouts($plan), 0, $limit);
    }

    /** @var array<string,array> per plan, for one request */
    private array $candidates = [];

    private function rankHoldouts(AssortmentPlan $plan): array
    {
        $groups = app(PeerGroups::class)->build((int) $plan->tenant_id);
        $members = $groups['groups'][(string) $plan->peer_group]['members'] ?? [];
        $busy = AssortmentPlan::where('tenant_id', $plan->tenant_id)->where('category', $plan->category)->whereKeyNot($plan->id)
            ->whereIn('status', [AssortmentPlan::STATUS_ACCEPTED, AssortmentPlan::STATUS_IN_PROGRESS])->get(['store_id', 'holdouts', 'status']);
        $exclude = array_merge([(int) $plan->store_id], $busy->pluck('store_id')->map(fn ($id) => (int) $id)->all(),
            ...$busy->map(fn (AssortmentPlan $p) => $p->holdoutIds())->all());
        $ids = array_values(array_diff(array_map('intval', $members), $exclude));
        if ($ids === []) {
            return [];
        }

        $asOf = ($plan->as_of_date ?? now())->copy();
        $recent = [$asOf->copy()->subDays(55)->toDateString(), $asOf->toDateString()];
        $prior = [$asOf->copy()->subDays(111)->toDateString(), $asOf->copy()->subDays(56)->toDateString()];
        $all = array_merge($ids, [(int) $plan->store_id]);
        $sum = fn (array $range) => DB::table('sales_daily as s')->join('products as p', fn ($j) => $j->on('p.tenant_id', '=', 's.tenant_id')->on('p.sku', '=', 's.sku'))
            ->where('s.tenant_id', $plan->tenant_id)->whereIn('s.store_id', $all)->whereRaw('TRIM(p.category) = ?', [$plan->category])
            ->whereBetween('s.date', $range)->groupBy('s.store_id')
            ->selectRaw('s.store_id, COALESCE(SUM(CASE WHEN s.revenue > 0 THEN s.revenue ELSE 0 END), 0) AS r')->pluck('r', 'store_id');
        $now = $sum($recent);
        $before = $sum($prior);
        $trend = fn (int $id) => (float) ($before[$id] ?? 0) > 0 ? (float) ($now[$id] ?? 0) / (float) $before[$id] - 1 : null;
        $base = (float) ($now[(int) $plan->store_id] ?? 0);
        $baseTrend = $trend((int) $plan->store_id);
        $names = DB::table('stores')->where('tenant_id', $plan->tenant_id)->whereIn('id', $ids)->pluck('name', 'id');

        $out = [];
        foreach ($ids as $id) {
            $s = (float) ($now[$id] ?? 0);
            if ($s <= 0 || $base <= 0) {
                continue;   // a store that does not sell the category cannot show what it would have done
            }
            $t = $trend($id);
            $gap = abs(log($s / $base));
            $tGap = $t !== null && $baseTrend !== null ? abs($t - $baseTrend) : 0.5;
            $out[] = [
                'store_id' => $id,
                'name'     => (string) ($names[$id] ?? 'store #' . $id),
                'why'      => sprintf('%s sales %d%% of this store\'s in the last 8 weeks%s', $plan->category, (int) round($s / $base * 100),
                    $t !== null && $baseTrend !== null ? '; trend ' . self::trendWords($t) . ' against ' . self::trendWords($baseTrend) . ' here' : ''),
                'score'    => round($gap + $tGap, 4),
            ];
        }
        usort($out, fn ($a, $b) => [$a['score'], $a['store_id']] <=> [$b['score'], $b['store_id']]);

        return $out;
    }

    private static function trendWords(float $t): string
    {
        $p = (int) round($t * 100);

        return $p === 0 ? 'flat' : ($p > 0 ? '+' : '−') . abs($p) . '%';
    }

    /** The plan holding this plan's store back in its category, if any. */
    public function heldBy(AssortmentPlan $plan): ?AssortmentPlan
    {
        return AssortmentPlan::where('tenant_id', $plan->tenant_id)->where('category', $plan->category)->whereKeyNot($plan->id)
            ->whereIn('status', [AssortmentPlan::STATUS_ACCEPTED, AssortmentPlan::STATUS_IN_PROGRESS])->whereNotNull('holdouts')
            ->get()->first(fn (AssortmentPlan $p) => in_array((int) $plan->store_id, $p->holdoutIds(), true));
    }

    public function heldMessage(AssortmentPlan $plan, AssortmentPlan $held): string
    {
        return ($plan->store?->name ?? 'This store') . ' is held back in ' . $plan->category . ' as the comparison for range plan #' . $held->id
            . ' (' . ($held->store?->name ?? 'store #' . $held->store_id) . ') until that plan is measured'
            . ($held->measure_after ? ', after ' . $held->measure_after->format('j M Y') : '') . '. Release it from that plan to change it now.';
    }

    /** Stop holding stores back: the plan is then measured against the other similar stores only. */
    public function release(AssortmentPlan $plan, User $by, array $storeIds): void
    {
        abort_unless($plan->holding(), 422, 'This plan holds no stores back.');
        $ids = array_map('intval', $storeIds);
        $left = array_values(array_filter((array) $plan->holdouts, fn ($h) => ! in_array((int) $h['store_id'], $ids, true)));
        $gone = array_values(array_filter((array) $plan->holdouts, fn ($h) => in_array((int) $h['store_id'], $ids, true)));
        if ($gone === []) {
            return;
        }
        $plan->forceFill(['holdouts' => $left === [] ? null : $left])->save();
        $this->audit($plan, $by, 'assortment.plan_holdouts_released', 'Released ' . implode(', ', array_column($gone, 'name')) . ' from range plan #' . $plan->id);
    }

    public function reject(AssortmentPlan $plan, User $by, string $reason): void
    {
        abort_unless(in_array($plan->status, [AssortmentPlan::STATUS_PROPOSED], true), 422, 'Only a proposed plan can be rejected.');
        $plan->forceFill(['status' => AssortmentPlan::STATUS_REJECTED, 'decided_by' => $by->id, 'decided_at' => now(),
            'decision_note' => mb_substr(trim($reason), 0, 500)])->save();
        $this->audit($plan, $by, 'assortment.plan_rejected', 'Rejected range plan: ' . $plan->headline());
        $this->learning->planDecided($plan, false);
    }

    public function markDone(AssortmentPlan $plan, User $by, ?string $doneOn = null): void
    {
        abort_unless($plan->status === AssortmentPlan::STATUS_ACCEPTED, 422, 'Only an accepted plan can be marked done.');
        $done = $doneOn ? \Illuminate\Support\Carbon::parse($doneOn) : now();
        foreach ($this->gapIds($plan) as $gapId) {
            $gap = AssortmentGap::where('tenant_id', $plan->tenant_id)->find($gapId);
            if ($gap && $gap->status === AssortmentGap::STATUS_ACCEPTED && $gap->task_status === AssortmentGap::TASK_TO_DO) {
                $this->decisions->markDone($gap, $by, 'Range plan #' . $plan->id . ' done', $done->toDateString());
            }
        }
        $plan->forceFill([
            'status'        => AssortmentPlan::STATUS_IN_PROGRESS,
            'done_at'       => $done,
            'done_by'       => $by->id,
            'measure_after' => $done->copy()->addDays((int) config('assortment.measure_days', 56))->toDateString(),
        ])->save();
        $this->audit($plan, $by, 'assortment.plan_done', 'Range plan done: ' . $plan->headline());
    }

    /** @return array<int,int> the decisions behind the plan's ticked changes */
    public function gapIds(AssortmentPlan $plan): array
    {
        $ids = [];
        foreach ($plan->actionable() as $c) {
            if ($c['ticked'] ?? true) {
                array_push($ids, ...array_filter([$c['gap_id'] ?? null, $c['out_gap_id'] ?? null]));
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    // ── The plan review (gate 2) ──────────────────────────────────────────────

    public function sample(Tenant $tenant)
    {
        return AssortmentPlan::with('store:id,name')->where('tenant_id', $tenant->id)
            ->whereIn('status', [AssortmentPlan::STATUS_DRAFT, AssortmentPlan::STATUS_PROPOSED])->where('feasible', true)
            ->orderByRaw('value_mid * confidence DESC')->orderBy('id')->limit(self::SAMPLE)->get();
    }

    /** @return array{total:int, needed:int, reviewed:int, sensible:int, rate:?float, passed:bool, decisions_live:bool, live:bool} */
    public function gate(Tenant $tenant): array
    {
        $sample = $this->sample($tenant);
        $total = AssortmentPlan::where('tenant_id', $tenant->id)->whereIn('status', [AssortmentPlan::STATUS_DRAFT, AssortmentPlan::STATUS_PROPOSED])->where('feasible', true)->count();
        $needed = $sample->count();
        $reviewed = $sample->whereNotNull('review_verdict')->count();
        $sensible = $sample->where('review_verdict', AssortmentGap::VERDICT_SENSIBLE)->count();
        $rate = $reviewed > 0 ? $sensible / $reviewed : null;
        $pass = (float) config('assortment.gate_pass', 0.70);

        return [
            'total' => $total, 'needed' => $needed, 'reviewed' => $reviewed, 'sensible' => $sensible, 'rate' => $rate,
            'passed' => $needed > 0 && $reviewed >= $needed && $rate !== null && $rate >= $pass,
            'decisions_live' => TenantAssortment::isLive($tenant), 'live' => TenantAssortment::plansLive($tenant),
        ];
    }

    public function review(AssortmentPlan $plan, User $by, string $verdict): void
    {
        abort_unless(in_array($verdict, [AssortmentGap::VERDICT_SENSIBLE, AssortmentGap::VERDICT_NOT_SENSIBLE], true), 422);
        $plan->forceFill(['review_verdict' => $verdict, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
    }

    public function goLive(Tenant $tenant, User $by): bool
    {
        $g = $this->gate($tenant);
        if (! $g['passed'] || ! $g['decisions_live']) {
            return false;
        }
        TenantAssortment::update($tenant, ['plans_live' => true, 'plans_live_at' => now()->toIso8601String()]);
        AssortmentPlan::where('tenant_id', $tenant->id)->where('status', AssortmentPlan::STATUS_DRAFT)->update(['status' => AssortmentPlan::STATUS_PROPOSED]);
        $this->auditTenant($tenant, $by, 'assortment.plans_live', 'Range plans opened to users (plan review passed)');

        return true;
    }

    public function pause(Tenant $tenant, User $by): void
    {
        TenantAssortment::update($tenant, ['plans_live' => false]);
        AssortmentPlan::where('tenant_id', $tenant->id)->where('status', AssortmentPlan::STATUS_PROPOSED)->update(['status' => AssortmentPlan::STATUS_DRAFT]);
        $this->auditTenant($tenant, $by, 'assortment.plans_paused', 'Range plans put back behind the plan review');
    }

    private function audit(AssortmentPlan $plan, User $by, string $event, string $summary): void
    {
        try {
            AuditLog::create(['tenant_id' => $plan->tenant_id, 'user_id' => $by->id, 'event_type' => $event,
                'description' => mb_substr($summary, 0, 250), 'new_value' => ['plan_id' => $plan->id, 'status' => $plan->status], 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function auditTenant(Tenant $tenant, User $by, string $event, string $summary): void
    {
        try {
            AuditLog::create(['tenant_id' => $tenant->id, 'user_id' => $by->id, 'event_type' => $event, 'description' => $summary, 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
