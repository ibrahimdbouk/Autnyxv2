<?php

namespace App\Console\Commands;

use App\Models\Anomaly;
use App\Models\AnomalySetting;
use App\Models\AuditLog;
use App\Models\Investigation;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Anomaly\AnomalyDismissal;
use App\Services\Anomaly\BaselineCalculatorService;
use App\Services\Anomaly\InvestigationCorrelationService;
use App\Services\Investigation\DeterministicRevenueAtRisk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * WP4.6 (D5) — switch one tenant to the corrected detection (v2) and rebuild
 * what the old rules produced. Dry run by default (prints the plan and counts).
 *
 *   1. classify existing anomalies by money type;
 *   2. align seeded trend floors with the tidy-tail floor (only values still at
 *      the old seeded default — a floor someone chose is left alone);
 *   3. SUPERSEDE, never delete: every live v1 anomaly is closed with reason
 *      superseded_by_recalibration; an investigation left with no live signal
 *      and no human work (open, no actions, no outcome, unassigned) is closed
 *      with a note. Anything a person is working on stays open, and the new
 *      signals join it;
 *   4. reset sensitivity inflated before W1;
 *   5. switch the tenant to v2 and build v2 baselines;
 *   6. a full v2 detection run, then correlation;
 *   7. recompute revenue at risk / capital on every active investigation.
 *
 * Re-running it after it has applied only repeats steps 5–7 (idempotent).
 */
class RecalibrateDetectionCommand extends Command
{
    protected $signature = 'detection:recalibrate {--tenant= : Tenant ID (required)} {--apply : Do it (otherwise a dry run)}';

    protected $description = 'Switch a tenant to the corrected detection rules and supersede what the old rules produced (dry run unless --apply).';

    private const OLD_TREND_FLOORS = ['demand_erosion' => 500, 'demand_seasonality_breach' => 1000];

    public function handle(AnomalyDetectionService $detector): int
    {
        $tenant = Tenant::find((int) $this->option('tenant'));
        if (! $tenant) {
            $this->error('Pass a valid --tenant=<id>.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $already = AnomalyDetectionService::rulesV2For($tenant->id);
        if ($reason = $detector->pendingImportBlock($tenant->id)) {
            $this->error("Not now — {$reason}.");

            return self::FAILURE;
        }

        $liveV1 = Anomaly::where('tenant_id', $tenant->id)->active();
        $closable = $this->closableInvestigations($tenant->id);
        $this->info("Tenant {$tenant->id} ({$tenant->name}) — " . ($already ? 'already on v2' : 'currently on v1') . ($apply ? '' : ' — DRY RUN'));
        $this->line('  live anomalies to supersede:        ' . ($already ? 0 : (clone $liveV1)->count()));
        $this->line('  investigations to close (no work):  ' . ($already ? 0 : $closable->count()));
        $this->line('  investigations kept (being worked): ' . ($already ? '-' : Investigation::where('tenant_id', $tenant->id)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])->count() - $closable->count()));

        if (! $apply) {
            $this->warn('Dry run. Share `detection:diff` first; then re-run with --apply.');

            return self::SUCCESS;
        }

        @ini_set('memory_limit', '1024M');
        Artisan::call('anomalies:classify-value', ['--tenant' => $tenant->id]);

        if (! $already) {
            $this->alignTrendFloors($tenant->id);
            $superseded = $this->supersede($tenant->id, $closable);
            $this->line("  superseded {$superseded['anomalies']} anomalies, closed {$superseded['investigations']} investigations");
            Artisan::call('baselines:repair-sensitivity', ['--tenant' => $tenant->id, '--apply' => true]);

            $settings = (array) ($tenant->settings ?? []);
            $settings['detection_rules_v2'] = true;
            $tenant->forceFill(['settings' => $settings])->save();
            AuditLog::create(['tenant_id' => $tenant->id, 'event_type' => 'detection_recalibrated',
                'description' => 'Switched to the corrected detection rules (W4); ' . $superseded['anomalies'] . ' anomalies superseded, '
                    . $superseded['investigations'] . ' idle investigations closed.',
                'new_value' => $superseded]);
        }

        app(BaselineCalculatorService::class)->computeForTenant($tenant->id);
        $lock = \App\Services\Pipeline\TenantDetectionLock::for($tenant->id);   // WP5.2: one writer per tenant
        try {
            $lock->block((int) config('pipeline.lock_wait', 900));
            $detector->runForTenant($tenant->id);
            app(InvestigationCorrelationService::class)->correlateForTenant($tenant->id);
        } finally {
            $lock->release();
        }

        // Active investigations only — a closed one keeps the figure it closed with.
        $calc = app(DeterministicRevenueAtRisk::class);
        Investigation::where('tenant_id', $tenant->id)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])->orderBy('id')
            ->chunkById(500, fn ($chunk) => $chunk->each(fn ($inv) => $calc->sync($inv)));

        $live = Anomaly::where('tenant_id', $tenant->id)->active()->count();
        $open = Investigation::where('tenant_id', $tenant->id)->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS]);
        $this->info("Done: {$live} live anomalies; " . (clone $open)->count() . ' active investigations; revenue at risk '
            . number_format((float) (clone $open)->sum('revenue_at_risk'), 0) . ', capital ' . number_format((float) (clone $open)->sum('capital_at_risk'), 0) . '.');

        return self::SUCCESS;
    }

    /** Open, unassigned investigations with no actions and no outcome — nobody is working them. */
    private function closableInvestigations(int $tenantId)
    {
        return Investigation::where('tenant_id', $tenantId)
            ->where('status', Investigation::STATUS_OPEN)
            ->whereNull('assigned_user_id')
            ->whereDoesntHave('actions')
            ->whereDoesntHave('outcome')
            ->pluck('id');
    }

    private function alignTrendFloors(int $tenantId): void
    {
        $floor = (float) config('detection.trend_min_revenue', 2000);
        foreach (self::OLD_TREND_FLOORS as $rule => $old) {
            $s = AnomalySetting::where('tenant_id', $tenantId)->where('rule_type', $rule)->first();
            $t = (array) ($s?->thresholds ?? []);
            if ($s && (float) ($t['min_revenue'] ?? $old) === (float) $old) {
                $t['min_revenue'] = $floor;
                $s->update(['thresholds' => $t]);
            }
        }
    }

    /** @return array{anomalies:int, investigations:int} */
    private function supersede(int $tenantId, $closable): array
    {
        return DB::transaction(function () use ($tenantId, $closable) {
            $n = Anomaly::where('tenant_id', $tenantId)->active()->update([
                'dismissed_at'   => now(),
                'dismiss_reason' => AnomalyDismissal::REASON_SUPERSEDED,
            ]);
            $m = 0;
            foreach ($closable->chunk(1000) as $ids) {
                $m += Investigation::whereIn('id', $ids->all())->update([
                    'status'           => Investigation::STATUS_CLOSED,
                    'closed_at'        => now(),
                    'resolution_notes' => 'Superseded by the detection recalibration (W4): its signals came from rules that were corrected. '
                        . 'Anything still true is detected again and opens a fresh investigation.',
                ]);
            }

            return ['anomalies' => $n, 'investigations' => $m];
        });
    }
}
