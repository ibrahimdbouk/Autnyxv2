<?php

namespace App\Jobs;

use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Anomaly\InvestigationCorrelationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the full detection pipeline (detect → correlate) for one tenant off the
 * web request, so a large, multi-minute scan never blocks a page. Requires a
 * queue worker to be running on the environment.
 *
 * - Tenant-isolated: only ever touches the given tenant.
 * - Idempotent: detection upserts anomalies and prunes stale ones; correlation
 *   is idempotent; so a re-run converges rather than duplicating.
 * - ShouldBeUnique: prevents piling up duplicate detection runs for a tenant
 *   while one is queued/running.
 */
class RunTenantDetectionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Detection over large datasets can take a while; give it room. */
    public int $timeout = 1800;

    /** Heavy + idempotent — do not auto-retry a failed multi-minute run. */
    public int $tries = 1;

    /** A queued+running unique lock is released after at most this many seconds. */
    public int $uniqueFor = 1800;

    public function __construct(public int $tenantId)
    {
    }

    public function uniqueId(): string
    {
        return 'detect-tenant-' . $this->tenantId;
    }

    public function handle(AnomalyDetectionService $detector, InvestigationCorrelationService $correlator): void
    {
        $detector->runForTenant($this->tenantId);
        $correlator->correlateForTenant($this->tenantId);
    }
}
