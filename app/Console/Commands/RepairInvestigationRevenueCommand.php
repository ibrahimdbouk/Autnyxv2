<?php

namespace App\Console\Commands;

use App\Models\Investigation;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Investigation\DeterministicRevenueAtRisk;
use Illuminate\Console\Command;

/**
 * investigations:repair-revenue — WP1.1 (audit C1) one-off data repair.
 *
 * Before WP1.1 the AI narrator could overwrite investigations.revenue_at_risk
 * with its own estimate, and queue:tidy-tail then auto-snoozed on that value.
 * This command, per investigation:
 *   1. recomputes the deterministic revenue_at_risk (Σ anomaly revenue_impact);
 *   2. if the stored value differed and the investigation had been narrated, keeps
 *      the old (AI-written) value in ai_revenue_estimate so nothing is lost;
 *   3. lifts auto_low_value_tail snoozes whose deterministic value is at/above
 *      the materiality floor (they were snoozed on the AI's number);
 *   4. writes an audit row for every change.
 *
 * Idempotent: a second run changes nothing. Use --dry to preview.
 */
class RepairInvestigationRevenueCommand extends Command
{
    protected $signature = 'investigations:repair-revenue
        {--tenant= : Only this tenant ID}
        {--floor= : Materiality floor (default: config detection.trend_min_revenue or 2000)}
        {--dry : Report what would change without writing}';

    protected $description = 'Restore deterministic revenue_at_risk on every investigation and lift snoozes made on AI estimates';

    public function handle(DeterministicRevenueAtRisk $calc): int
    {
        $dry   = (bool) $this->option('dry');
        $floor = $this->option('floor') !== null
            ? (float) $this->option('floor')
            : (float) config('detection.trend_min_revenue', 2000);

        $tenants = $this->option('tenant')
            ? Tenant::whereKey((int) $this->option('tenant'))->get()
            : Tenant::all();

        $rows = [];
        foreach ($tenants as $tenant) {
            $changed = $unsnoozed = $scanned = 0;

            Investigation::where('tenant_id', $tenant->id)
                ->orderBy('id')
                ->chunkById(500, function ($investigations) use ($calc, $dry, $floor, &$changed, &$unsnoozed, &$scanned) {
                    foreach ($investigations as $inv) {
                        $scanned++;
                        $old = $inv->revenue_at_risk !== null ? round((float) $inv->revenue_at_risk, 2) : null;
                        $new = $calc->compute($inv);

                        $updates = [];
                        if ($old === null || abs($old - $new) >= 0.01) {
                            $updates['revenue_at_risk'] = $new;
                            if ($old !== null && $inv->ai_generated_at !== null && $inv->ai_revenue_estimate === null) {
                                $updates['ai_revenue_estimate'] = $old;
                            }
                        }

                        $wasAutoSnoozed = $inv->snooze_reason === 'auto_low_value_tail'
                            && $inv->snoozed_until !== null
                            && $inv->snoozed_until->isFuture();
                        if ($wasAutoSnoozed && $new >= $floor) {
                            $updates += [
                                'snoozed_until' => null,
                                'snooze_reason' => null,
                                'snooze_notes'  => null,
                                'snoozed_by'    => null,
                                'snoozed_at'    => null,
                            ];
                        }

                        if ($updates === []) {
                            continue;
                        }

                        if (array_key_exists('revenue_at_risk', $updates)) {
                            $changed++;
                        }
                        if (array_key_exists('snoozed_until', $updates)) {
                            $unsnoozed++;
                        }

                        if ($dry) {
                            continue;
                        }

                        $inv->update($updates);

                        $parts = [];
                        if (array_key_exists('revenue_at_risk', $updates)) {
                            $parts[] = sprintf('revenue at risk restored to the calculated %s (was %s, an AI estimate)',
                                number_format($new, 2), $old === null ? 'empty' : number_format($old, 2));
                        }
                        if (array_key_exists('snoozed_until', $updates)) {
                            $parts[] = 'auto-snooze lifted: the calculated value is above the materiality floor';
                        }
                        AuditLogger::log($inv, 'revenue_repaired', 'Data repair (WP1.1): ' . implode('; ', $parts) . '.', null);
                    }
                });

            $rows[] = [$tenant->id, $tenant->name, $scanned, $changed, $unsnoozed];
        }

        $this->table(['Tenant', 'Name', 'Investigations', 'Revenue restored', 'Snoozes lifted'], $rows);
        $this->info($dry ? 'Dry run — nothing was written.' : 'Repair complete.');

        return self::SUCCESS;
    }
}
