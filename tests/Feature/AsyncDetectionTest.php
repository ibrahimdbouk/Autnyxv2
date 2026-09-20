<?php

namespace Tests\Feature;

use App\Jobs\RunTenantDetectionJob;
use App\Models\DetectionDirtyKey;
use App\Services\Detection\DirtyKeyRecorder;
use App\Services\Detection\TenantDetectionRunner;
use Tests\TestCase;

/**
 * Async detection — the queued, off-request detection path.
 *
 * Detection runs off the web request as RunTenantDetectionJob, dispatched by the
 * nightly schedule, the `anomalies:detect --queue` command, and every completed
 * import. These guard the pieces that make that reliable:
 *   - the job's identity (one run per tenant) and mode plumbing;
 *   - the retry_after > timeout invariant (the MaxAttemptsExceeded regression);
 *   - the shared TenantDetectionRunner orchestration the job and CLI both use.
 */
class AsyncDetectionTest extends TestCase
{
    public function test_job_is_unique_per_tenant_and_carries_mode(): void
    {
        $job = new RunTenantDetectionJob(7, 'incremental');

        $this->assertSame('detect-tenant-7', $job->uniqueId());
        $this->assertSame(7, $job->tenantId);
        $this->assertSame('incremental', $job->mode);

        // No mode given → the runner resolves it from config('detection.mode').
        $this->assertNull((new RunTenantDetectionJob(7))->mode);
    }

    public function test_retry_after_exceeds_job_timeout(): void
    {
        // Regression guard for the MaxAttemptsExceededException class of failure:
        // a run that outlasts retry_after is made visible again and re-reserved
        // mid-flight, tripping tries=1. retry_after MUST exceed the job timeout.
        $this->assertGreaterThan(
            (new RunTenantDetectionJob(1))->timeout,
            (int) config('queue.connections.database.retry_after'),
            'DB queue retry_after must exceed the detection job timeout.'
        );
    }

    public function test_incremental_run_on_quiet_tenant_just_stamps_watermark(): void
    {
        $tenant = $this->createTenant();
        $this->assertNull($tenant->fresh()->last_detection_at);

        // Nothing dirty, nothing open → empty scope → no scan, watermark advances.
        app(TenantDetectionRunner::class)->run($tenant->id, 'incremental');

        $this->assertNotNull($tenant->fresh()->last_detection_at);
    }

    public function test_full_run_clears_dirty_queue_and_stamps_watermark(): void
    {
        $tenant = $this->createTenant();

        app(DirtyKeyRecorder::class)->record(
            $tenant->id,
            [['store_id' => null, 'sku' => 'SKU-ASYNC']],
            DetectionDirtyKey::REASON_IMPORT,
        );
        $this->assertSame(1, DetectionDirtyKey::where('tenant_id', $tenant->id)->count());

        // A full run scans everything, then clears the tenant's dirty queue so it
        // can't grow unbounded while running in full mode, and stamps the watermark.
        app(TenantDetectionRunner::class)->run($tenant->id, 'full');

        $this->assertSame(0, DetectionDirtyKey::where('tenant_id', $tenant->id)->count());
        $this->assertNotNull($tenant->fresh()->last_detection_at);
    }
}
