<?php

namespace App\Console\Commands;

use App\Platform\Traceability\ExpiryMonitor;
use Illuminate\Console\Command;

/**
 * P3.3 — scan a tenant's batches for expiry anomalies: expired-but-in-stock
 * (critical) and expiring-soon (warning). Read-only.
 */
class ExpiryScanCommand extends Command
{
    protected $signature = 'traceability:expiry-scan {tenant : Tenant id} {--days=7}';

    protected $description = "Scan a tenant's batches for expiry anomalies (expired + expiring soon).";

    public function handle(ExpiryMonitor $monitor): int
    {
        $tenantId = (int) $this->argument('tenant');
        $anomalies = $monitor->anomalies($tenantId, (int) $this->option('days'));

        if ($anomalies === []) {
            $this->info("No expiry anomalies for tenant {$tenantId}.");
            return self::SUCCESS;
        }

        $this->table(
            ['batch_id', 'sku', 'batch_code', 'severity', 'days_to_expiry'],
            array_map(fn (array $a) => [
                $a['batch_id'], $a['sku'], $a['batch_code'], $a['severity'], $a['days_to_expiry'],
            ], $anomalies),
        );

        $critical = count(array_filter($anomalies, fn ($a) => $a['severity'] === 'critical'));
        $this->warn(count($anomalies) . " anomaly(ies), {$critical} critical (expired, in stock).");

        return self::SUCCESS;
    }
}
