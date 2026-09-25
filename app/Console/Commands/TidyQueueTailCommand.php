<?php

namespace App\Console\Commands;

use App\Filament\Pages\ActionQueue;
use App\Models\Investigation;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * queue:tidy-tail — auto-snooze the genuinely low-value trend tail.
 *
 * Trend campaigns (demand erosion, seasonal shift) can carry a long tail of
 * individually immaterial SKUs. Left in the open queue they inflate the count a
 * human sees without ever being worth an individual action. This snoozes the
 * ones below a materiality floor so they leave the active queue — they are NOT
 * deleted or resolved: they stay recorded, the campaign still summarises them,
 * and they resurface (WP4.5, TidyTail) when they worsen past the floor — checked
 * here each run and at correlation — or when an incident anomaly joins them.
 *
 * Deliberately conservative — only touches investigations that are:
 *   • status = open (never one already in progress / being worked),
 *   • below the money floor (revenue_at_risk < floor),
 *   • purely trend (no incident-type anomaly attached),
 *   • not already snoozed, and with no actions on them.
 */
class TidyQueueTailCommand extends Command
{
    protected $signature = 'queue:tidy-tail
        {--tenant= : Only this tenant ID}
        {--floor= : Money floor in tenant currency (default: config detection.trend_min_revenue or 2000)}
        {--days=30 : How long to snooze}
        {--dry : Report what would be snoozed without changing anything}';

    protected $description = 'Auto-snooze the low-value trend tail so the active queue reflects what is worth working';

    public function handle(): int
    {
        $trendRules = [];
        foreach (ActionQueue::campaignsMap() as $cfg) {
            if (($cfg['kind'] ?? '') === 'trend') {
                $trendRules = array_merge($trendRules, $cfg['rules']);
            }
        }
        $trendRules = array_values(array_unique($trendRules));

        $floor = $this->option('floor') !== null
            ? (float) $this->option('floor')
            : (float) config('detection.trend_min_revenue', 2000);
        $days = max(1, (int) $this->option('days'));
        $dry  = (bool) $this->option('dry');

        $tenants = $this->option('tenant')
            ? Tenant::whereKey((int) $this->option('tenant'))->get()
            : Tenant::all();

        $totalSnoozed = 0;
        foreach ($tenants as $tenant) {
            // Resurface first: auto-snoozed items that have since crossed the floor.
            $resurfaced = 0;
            if (! $dry) {
                Investigation::where('tenant_id', $tenant->id)
                    ->where('snooze_reason', \App\Services\Investigation\TidyTail::REASON)
                    ->where('snoozed_until', '>', now())
                    ->where('revenue_at_risk', '>=', $floor)
                    ->get()
                    ->each(function ($inv) use (&$resurfaced) {
                        $resurfaced += \App\Services\Investigation\TidyTail::resurfaceIfWarranted($inv) ? 1 : 0;
                    });
            }

            $query = Investigation::where('tenant_id', $tenant->id)
                ->where('status', Investigation::STATUS_OPEN)
                ->where('revenue_at_risk', '<', $floor)
                ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
                ->whereDoesntHave('actions')
                // has at least one trend anomaly …
                ->whereHas('anomalies', fn ($q) => $q->whereIn('rule_type', $trendRules))
                // … and NO incident (non-trend) anomaly — purely trend.
                ->whereDoesntHave('anomalies', fn ($q) => $q->whereNotIn('rule_type', $trendRules));

            $count = (clone $query)->count();

            if ($dry) {
                $this->info("Tenant {$tenant->id} ({$tenant->name}): would snooze {$count} (floor {$floor}).");
                continue;
            }

            if ($count > 0) {
                (clone $query)->update([
                    'snoozed_until' => now()->addDays($days),
                    'snooze_reason' => 'auto_low_value_tail',
                    'snooze_notes'  => "Auto-snoozed: below materiality floor ({$floor}) in a trend campaign.",
                    'snoozed_by'    => null, // system
                    'snoozed_at'    => now(),
                ]);
            }

            $totalSnoozed += $count;
            $this->info("Tenant {$tenant->id} ({$tenant->name}): snoozed {$count} low-value trend items for {$days}d; resurfaced {$resurfaced}.");
        }

        Log::info("[queue:tidy-tail] snoozed {$totalSnoozed} investigation(s) across " . $tenants->count() . ' tenant(s).');

        return self::SUCCESS;
    }
}
