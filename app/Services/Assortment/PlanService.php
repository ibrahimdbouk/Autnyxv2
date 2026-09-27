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

    /** @param array<int,string> $untick change keys the person unticked */
    public function accept(AssortmentPlan $plan, User $by, ?int $assigneeId = null, ?string $dueAt = null, ?string $note = null, array $untick = []): void
    {
        abort_unless($plan->status === AssortmentPlan::STATUS_PROPOSED && $plan->feasible, 422, 'Only a proposed, feasible plan can be accepted.');

        DB::transaction(function () use ($plan, $by, $assigneeId, $dueAt, $note, $untick) {
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
            abort_if($firstGap === null && collect($changes)->where('ticked', true)->where('kind', '<>', AssortmentPlan::PROTECT)->isNotEmpty(), 422,
                'The decisions behind this plan are no longer open — rebuild it with Run now.');

            $base = $plan->impact['baseline'] ?? ['sales' => 0, 'margin' => 0, 'stock' => 0, 'count' => $plan->current_count];
            $count = $plan->current_count + collect($changes)->where('ticked', true)->sum(fn ($c) => match ($c['kind']) {
                AssortmentPlan::ADD => 1, AssortmentPlan::DELIST => -1, default => 0,
            });
            $plan->forceFill([
                'changes'        => $changes,
                'impact'         => $this->optimiser->impact($changes, $base, $count) + ['margin_known' => $plan->impact['margin_known'] ?? false],
                'proposed_count' => $count,
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
