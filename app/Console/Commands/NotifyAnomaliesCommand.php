<?php

namespace App\Console\Commands;

use App\Mail\AnomalyDigestMail;
use App\Models\Anomaly;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyAnomaliesCommand extends Command
{
    protected $signature = 'anomalies:notify {--tenant= : Specific tenant ID} {--dry-run : Preview without sending}';

    protected $description = 'Send anomaly digest emails for tenants that have a notification_email set';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $dryRun   = $this->option('dry-run');

        $query = Tenant::whereNotNull('notification_email');
        if ($tenantId) {
            $query->where('id', $tenantId);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants with notification_email configured.');
            return Command::SUCCESS;
        }

        $sent = 0;
        foreach ($tenants as $tenant) {
            try {
                $severities = [];
                if ($tenant->notify_on_high)   $severities[] = 'high';
                if ($tenant->notify_on_medium)  $severities[] = 'medium';

                if (empty($severities)) continue;

                // Unnotified, non-dismissed anomalies matching tenant's severity preference
                $anomalies = Anomaly::where('tenant_id', $tenant->id)
                    ->whereNull('notified_at')
                    ->whereNull('dismissed_at')
                    ->whereIn('severity', $severities)
                    ->orderByRaw("CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
                    ->orderByDesc('detected_at')
                    ->get();

                if ($anomalies->isEmpty()) {
                    $this->line("  {$tenant->name}: no new anomalies to notify.");
                    continue;
                }

                $this->line("  {$tenant->name}: {$anomalies->count()} anomaly(ies) → {$tenant->notification_email}");

                if (!$dryRun) {
                    Mail::to($tenant->notification_email)
                        ->send(new AnomalyDigestMail($tenant, $anomalies));

                    // Mark as notified
                    Anomaly::whereIn('id', $anomalies->pluck('id'))
                        ->update(['notified_at' => now()]);
                }

                $sent++;
            } catch (\Throwable $e) {
                $this->error("  {$tenant->name} FAILED: {$e->getMessage()}");
                Log::error("[anomalies:notify] Tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $this->info($dryRun
            ? "Dry run complete — {$sent} tenant(s) would receive email."
            : "Notifications sent to {$sent} tenant(s)."
        );

        return Command::SUCCESS;
    }
}
