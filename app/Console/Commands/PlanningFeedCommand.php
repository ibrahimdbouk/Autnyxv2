<?php

namespace App\Console\Commands;

use App\Platform\Planning\PlanningSignalFeed;
use Illuminate\Console\Command;

/**
 * P2.4 — show the outbound sensing feed a tenant's planning system would pull:
 * the unconsumed exceptions / root-cause / recovery signals as canonical
 * envelopes. Read-only (does not acknowledge); a real consumer marks consumed
 * after it has taken them.
 */
class PlanningFeedCommand extends Command
{
    protected $signature = 'planning:feed {tenant : Tenant id} {--limit=50}';

    protected $description = "Show the unconsumed outbound planning-signal feed for a tenant.";

    public function handle(PlanningSignalFeed $feed): int
    {
        $tenantId = (int) $this->argument('tenant');
        $envelopes = $feed->feed($tenantId, (int) $this->option('limit'));

        if ($envelopes->isEmpty()) {
            $this->info("No unconsumed planning signals for tenant {$tenantId}.");
            return self::SUCCESS;
        }

        $this->table(
            ['id', 'type', 'sku', 'store', 'severity', 'delta', 'detected_at'],
            $envelopes->map(fn (array $e) => [
                $e['signal_id'],
                $e['signal_type'],
                $e['subject']['sku'] ?? '—',
                $e['subject']['store_id'] ?? '—',
                $e['severity'],
                $e['delta'] ?? '—',
                $e['detected_at'] ?? '—',
            ])->all(),
        );

        $this->info("{$envelopes->count()} signal(s) in the feed (contract v1.0).");

        return self::SUCCESS;
    }
}
