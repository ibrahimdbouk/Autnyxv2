<?php

namespace App\Jobs;

use App\Services\Detection\TenantDetectionRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the detection pipeline for one tenant OFF the web request, so a scan that
 * can take minutes never blocks a page (this is what makes detection async).
 * Requires a queue worker running on the environment — see claude/async-detection.md.
 *
 * Mode:
 *  - null      → the configured detection.mode (what the nightly schedule uses).
 *  - explicit  → e.g. imports dispatch 'incremental' so only the SKUs that just
 *                changed are scanned (fast, seconds); the nightly full scan is
 *                the correctness backstop.
 *
 * Delegates to TenantDetectionRunner so the queued path and the `anomalies:detect`
 * CLI share identical behaviour — including consuming the dirty-key queue and
 * stamping the detection watermark, which the old inline version skipped.
 *
 * - Tenant-isolated: only ever touches the given tenant.
 * - Idempotent: detection upserts anomalies and prunes stale ones; correlation is
 *   idempotent; so a re-run converges rather than duplicating.
 * - ShouldBeUnique: at most one detection run per tenant queued/running at a time,
 *   regardless of mode — a burst of imports coalesces into one run; SKUs from a
 *   dropped duplicate stay in the dirty queue and are picked up by the next run.
 *
 * IMPORTANT — the database queue's retry_after (config/queue.php) MUST exceed
 * $timeout. If a run outlasts retry_after the queue makes it visible again and a
 * second worker attempt trips tries=1 → MaxAttemptsExceededException. retry_after
 * is set to 1810 (> the 1800 timeout) precisely to prevent that.
 */
class RunTenantDetectionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Detection over large datasets can take a while; give it room. */
    public int $timeout = 1800;

    /**
     * Heavy + idempotent — a FAILED run is never retried (maxExceptions = 1),
     * but a run that found the tenant's detection lock taken is released and
     * tried again later (WP5.2), up to this many times.
     */
    public int $tries = 12;

    public int $maxExceptions = 1;

    /** A queued+running unique lock is released after at most this many seconds. */
    public int $uniqueFor = 1800;

    public function __construct(public int $tenantId, public ?string $mode = null)
    {
    }

    /** One detection run per tenant at a time, whatever the mode. */
    public function uniqueId(): string
    {
        return 'detect-tenant-' . $this->tenantId;
    }

    public function handle(TenantDetectionRunner $runner): void
    {
        try {
            $runner->run($this->tenantId, $this->mode);
        } catch (\App\Services\Pipeline\DetectionBusy) {
            // The nightly chain (or another run) is scanning this tenant; the
            // dirty keys wait, so try again in a few minutes.
            $this->release(300);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('[RunTenantDetectionJob] detection run failed', [
            'tenant_id' => $this->tenantId,
            'mode'      => $this->mode ?? config('detection.mode', 'full'),
            'error'     => $e->getMessage(),
        ]);
    }
}
