<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGap;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use App\Services\Org\OrgDirectory;

/**
 * A5 — what a person does with a range decision.
 *
 *   accept   → becomes a task (owner, due date); the value at that moment is kept
 *   reject   → kept with its reason so the same call is not proposed again
 *   done     → the task is complete; the result is measured `measure_days` later
 *   cancel   → an accepted decision that will not be carried out
 *
 * Autnyx never changes the range in the customer's system — the task is the
 * record of what the team did in theirs (recommend, never execute).
 */
class DecisionService
{
    public function __construct(private OrgDirectory $org) {}

    public function accept(AssortmentGap $gap, User $by, ?int $assigneeId = null, ?string $dueAt = null, ?string $note = null): void
    {
        $this->assertActionable($gap, [AssortmentGap::STATUS_OPEN]);

        $gap->forceFill([
            'status'                => AssortmentGap::STATUS_ACCEPTED,
            'decided_by'            => $by->id,
            'decided_at'            => now(),
            'decision_note'         => $this->clip($note),
            'value_mid_at_decision' => $gap->value_mid,
            'assignee_id'           => $assigneeId ?? $this->defaultAssignee($gap),
            'due_at'                => $dueAt ? \Illuminate\Support\Carbon::parse($dueAt)->endOfDay() : now()->addDays(14)->endOfDay(),
            'task_status'           => AssortmentGap::TASK_TO_DO,
        ])->save();

        $this->audit($gap, $by, 'assortment.accepted', 'Accepted: ' . $gap->headline());
    }

    public function reject(AssortmentGap $gap, User $by, string $reason): void
    {
        $this->assertActionable($gap, [AssortmentGap::STATUS_OPEN]);

        $gap->forceFill([
            'status'        => AssortmentGap::STATUS_REJECTED,
            'decided_by'    => $by->id,
            'decided_at'    => now(),
            'decision_note' => $this->clip($reason),
        ])->save();

        $this->audit($gap, $by, 'assortment.rejected', 'Rejected: ' . $gap->headline());
    }

    public function markDone(AssortmentGap $gap, User $by, ?string $note = null, ?string $doneOn = null): void
    {
        abort_unless($gap->status === AssortmentGap::STATUS_ACCEPTED && $gap->task_status === AssortmentGap::TASK_TO_DO, 422, 'Only an accepted task that is still to do can be marked done.');

        $done = $doneOn ? \Illuminate\Support\Carbon::parse($doneOn) : now();
        $gap->forceFill([
            'task_status'   => AssortmentGap::TASK_DONE,
            'done_at'       => $done,
            'done_by'       => $by->id,
            'decision_note' => $note !== null && trim($note) !== '' ? $this->clip(trim(($gap->decision_note ? $gap->decision_note . ' · ' : '') . $note)) : $gap->decision_note,
            'measure_after' => $done->copy()->addDays((int) config('assortment.measure_days', 56))->toDateString(),
        ])->save();

        $this->audit($gap, $by, 'assortment.done', 'Done: ' . $gap->headline());
    }

    public function cancel(AssortmentGap $gap, User $by, string $reason): void
    {
        abort_unless($gap->status === AssortmentGap::STATUS_ACCEPTED && $gap->task_status === AssortmentGap::TASK_TO_DO, 422);

        $gap->forceFill([
            'task_status'   => AssortmentGap::TASK_CANCELLED,
            'decision_note' => $this->clip(trim(($gap->decision_note ? $gap->decision_note . ' · ' : '') . 'Cancelled: ' . $reason)),
        ])->save();

        $this->audit($gap, $by, 'assortment.cancelled', 'Cancelled: ' . $gap->headline());
    }

    /** The store's manager, if the tenant has set one up. */
    private function defaultAssignee(AssortmentGap $gap): ?int
    {
        $store = Store::find($gap->store_id);
        if (! $store) {
            return null;
        }
        try {
            return $this->org->storeManagers($store)->first()?->id;
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertActionable(AssortmentGap $gap, array $statuses): void
    {
        abort_unless(in_array($gap->status, $statuses, true), 422, 'This decision can no longer be changed.');
    }

    private function clip(?string $s): ?string
    {
        return $s === null || trim($s) === '' ? null : mb_substr(trim($s), 0, 500);
    }

    private function audit(AssortmentGap $gap, User $by, string $event, string $summary): void
    {
        try {
            AuditLog::create([
                'tenant_id'   => $gap->tenant_id,
                'user_id'     => $by->id,
                'event_type'  => $event,
                'description' => mb_substr($summary, 0, 250),
                'new_value'   => ['decision_id' => $gap->id, 'type' => $gap->type, 'sku' => $gap->sku, 'store_id' => $gap->store_id],
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
