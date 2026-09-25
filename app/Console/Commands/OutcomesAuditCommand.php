<?php

namespace App\Console\Commands;

use App\Models\Anomaly;
use App\Models\InvestigationOutcome;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * W10 — recovery audit. For every outcome that carries a recovery figure:
 * was it measured, or only entered? Is the problem it says was recovered
 * still being detected on the same SKU and store? Does the figure look
 * like a formula (the same share of the value at risk on every outcome)?
 * Dry run by default; --apply marks unmeasured figures as claimed so they
 * stop counting as recovered (the figure itself is kept).
 */
class OutcomesAuditCommand extends Command
{
    protected $signature = 'outcomes:audit {--tenant= : One tenant} {--apply : Mark unmeasured recovery as claimed}';

    protected $description = 'Audit recorded recovery: measured vs claimed, and claims contradicted by live detection';

    public function handle(): int
    {
        $tenants = $this->option('tenant') ? Tenant::whereKey((int) $this->option('tenant'))->get() : Tenant::all();
        foreach ($tenants as $t) {
            $outcomes = InvestigationOutcome::where('tenant_id', $t->id)->where('observed_recovery', '>', 0)->with('investigation')->get();
            if ($outcomes->isEmpty()) {
                continue;
            }
            $this->info("Tenant {$t->id} — {$t->name}: {$outcomes->count()} outcome(s) with a recovery figure");
            $rows = [];
            $ratios = [];
            $claimed = 0;
            foreach ($outcomes as $o) {
                $inv = $o->investigation;
                $live = $inv ? Anomaly::where('tenant_id', $t->id)->active()
                    ->where('sku', $inv->primary_sku)
                    ->when($inv->primary_store_id, fn ($q) => $q->where('store_id', $inv->primary_store_id))
                    ->pluck('rule_type')->unique()->values()->all() : [];
                $measured = $o->measured_recovery !== null;
                if (! $measured) {
                    $claimed++;
                }
                if ((float) $o->revenue_at_risk > 0) {
                    $ratios[] = round((float) $o->observed_recovery / (float) $o->revenue_at_risk, 3);
                }
                $rows[] = [
                    $o->investigation_id,
                    \Illuminate\Support\Str::limit((string) $inv?->title, 48),
                    number_format((float) $o->observed_recovery, 2),
                    $measured ? number_format((float) $o->measured_recovery, 2) : '—',
                    $measured ? 'measured' : 'claimed',
                    $live ? 'STILL DETECTED: ' . implode(', ', $live) : 'cleared',
                    optional($o->recorded_at)->toDateTimeString() . ($inv?->resolved_at && $inv->opened_at && $inv->opened_at->diffInMinutes($inv->resolved_at, true) < 30 ? ' (resolved within 30 min of opening)' : ''),
                ];
            }
            $this->table(['Investigation', 'Title', 'Recovery entered', 'Measured', 'Status', 'Subject today', 'Recorded'], $rows);
            if (count($ratios) >= 3 && count(array_unique($ratios)) === 1) {
                $this->warn('Every figure is exactly ' . ($ratios[0] * 100) . '% of the value at risk — they look computed, not observed.');
            }

            if ($this->option('apply') && $claimed > 0) {
                $n = InvestigationOutcome::where('tenant_id', $t->id)->where('observed_recovery', '>', 0)->whereNull('measured_recovery')
                    ->where(fn ($q) => $q->whereNull('attribution_status')->orWhere('attribution_status', InvestigationOutcome::ATTR_NOT_ATTEMPTED))
                    ->update(['attribution_status' => InvestigationOutcome::ATTR_CLAIMED, 'updated_at' => now()]);
                \App\Models\AuditLog::create([
                    'tenant_id' => $t->id, 'event_type' => 'outcomes_audited',
                    'description' => "{$n} unmeasured recovery figure(s) marked as claimed (outcomes:audit).",
                ]);
                $this->info("{$n} marked claimed. They no longer count as recovered until measured.");
            }
        }

        return self::SUCCESS;
    }
}
