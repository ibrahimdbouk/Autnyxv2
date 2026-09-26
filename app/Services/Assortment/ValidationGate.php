<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGap;
use App\Models\Tenant;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A3.5 — the validation gate, per tenant.
 *
 * The engine's decisions stay in shadow until a person who knows the tenant's
 * range (their category manager) has reviewed the top `gate_sample` (50) of
 * each decision type — ranked as users would see them, by the middle of the
 * value range weighted by confidence — and at least `gate_pass` (70%) are
 * judged sensible. A type with fewer decisions than the sample needs all of
 * them reviewed. Going live opens the shadow decisions; pausing puts them back.
 */
class ValidationGate
{
    /** The review sample for one type: what a user would see first. */
    public function sample(Tenant $tenant, string $type): Collection
    {
        return AssortmentGap::query()->with(['store:id,name', 'product:id,name,category'])
            ->where('tenant_id', $tenant->id)->where('type', $type)
            ->whereIn('status', [AssortmentGap::STATUS_SHADOW, AssortmentGap::STATUS_OPEN])
            ->orderByRaw('value_mid * confidence DESC')->orderBy('id')
            ->limit((int) config('assortment.gate_sample', 50))
            ->get();
    }

    /**
     * Per type: decisions, sample size, reviewed, sensible, pass rate, passed.
     *
     * @return array{types: array<string,array<string,mixed>>, passed: bool, has_decisions: bool}
     */
    public function status(Tenant $tenant): array
    {
        $sampleSize = (int) config('assortment.gate_sample', 50);
        $pass       = (float) config('assortment.gate_pass', 0.70);
        $types      = [];
        $any        = false;
        $allPassed  = true;

        foreach (array_keys(AssortmentGap::TYPES) as $type) {
            $total = AssortmentGap::where('tenant_id', $tenant->id)->where('type', $type)
                ->whereIn('status', [AssortmentGap::STATUS_SHADOW, AssortmentGap::STATUS_OPEN])->count();
            $sample = $this->sample($tenant, $type);
            $needed = min($sampleSize, $total);
            $reviewed = $sample->whereNotNull('review_verdict')->count();
            $sensible = $sample->where('review_verdict', AssortmentGap::VERDICT_SENSIBLE)->count();
            $rate = $reviewed > 0 ? $sensible / $reviewed : null;
            $passed = $needed === 0 || ($reviewed >= $needed && $rate !== null && $rate >= $pass);

            $any = $any || $total > 0;
            $allPassed = $allPassed && $passed;
            $types[$type] = compact('total', 'needed', 'reviewed', 'sensible', 'rate', 'passed');
        }

        return ['types' => $types, 'passed' => $any && $allPassed, 'has_decisions' => $any];
    }

    public function review(AssortmentGap $gap, User $user, string $verdict, ?string $note = null): void
    {
        abort_unless(in_array($verdict, [AssortmentGap::VERDICT_SENSIBLE, AssortmentGap::VERDICT_NOT_SENSIBLE], true), 422);
        $gap->forceFill([
            'review_verdict' => $verdict,
            'review_note'    => $note !== null ? mb_substr(trim($note), 0, 500) : $gap->review_note,
            'reviewed_by'    => $user->id,
            'reviewed_at'    => now(),
        ])->save();
    }

    /** Open the tenant's decisions to users. Refuses unless the gate has passed. */
    public function goLive(Tenant $tenant, User $user): bool
    {
        $status = $this->status($tenant);
        if (! $status['passed']) {
            return false;
        }
        DB::transaction(function () use ($tenant, $status) {
            TenantAssortment::update($tenant, [
                'live'    => true,
                'live_at' => now()->toIso8601String(),
                'gate'    => collect($status['types'])->map(fn ($t) => ['reviewed' => $t['reviewed'], 'sensible' => $t['sensible']])->all(),
            ]);
            AssortmentGap::where('tenant_id', $tenant->id)->where('status', AssortmentGap::STATUS_SHADOW)
                ->update(['status' => AssortmentGap::STATUS_OPEN]);
        });
        $this->audit($tenant, $user, 'assortment.live', 'Assortment decisions opened to users (validation gate passed)');

        return true;
    }

    /** Put the tenant back behind the gate: open decisions return to shadow. */
    public function pause(Tenant $tenant, User $user): void
    {
        DB::transaction(function () use ($tenant) {
            TenantAssortment::update($tenant, ['live' => false]);
            AssortmentGap::where('tenant_id', $tenant->id)->where('status', AssortmentGap::STATUS_OPEN)
                ->update(['status' => AssortmentGap::STATUS_SHADOW]);
        });
        $this->audit($tenant, $user, 'assortment.paused', 'Assortment decisions put back behind the validation gate');
    }

    private function audit(Tenant $tenant, User $user, string $event, string $summary): void
    {
        try {
            AuditLog::create([
                'tenant_id'   => $tenant->id,
                'user_id'     => $user->id,
                'event_type'  => $event,
                'description' => $summary,
                'new_value'   => ['gate' => TenantAssortment::settings($tenant->fresh())['gate'] ?? null],
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
