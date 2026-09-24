<?php

namespace App\Services\Detection;

use App\Models\DetectionDirtyKey;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Anomaly\InvestigationCorrelationService;
use Illuminate\Support\Facades\Log;

/**
 * The single per-tenant detection orchestration: detect → correlate, then (for
 * the queue-consuming modes) consume the dirty-key queue and stamp the
 * watermark. Both the `anomalies:detect` CLI and the queued RunTenantDetectionJob
 * delegate here, so the scheduled run and the off-request (import-triggered)
 * run behave identically — there is exactly one place this logic lives.
 *
 *  full        — scan everything (unchanged); then clear the tenant's dirty queue
 *                so it can't grow unbounded while running in full mode.
 *  aggregate   — run only the rules the per-key incremental run skips, full scan;
 *                does not touch the queue (the incremental run owns it).
 *  incremental — scan only the changed + still-open SKUs (RunScope); on success,
 *                consume the queue up to the id folded into the scope and advance
 *                the watermark. A too-broad change set falls back to a full scan.
 *
 * See claude/incremental-detection-design.md and claude/async-detection.md.
 */
class TenantDetectionRunner
{
    public function __construct(
        private AnomalyDetectionService $detector,
        private InvestigationCorrelationService $correlator,
    ) {
    }

    /**
     * @param  int          $tenantId
     * @param  string|null  $mode  full|aggregate|incremental; null → config('detection.mode').
     */
    public function run(int $tenantId, ?string $mode = null): void
    {
        $mode = $mode ?: config('detection.mode', 'full');

        // WP1.2 (audit C3): if a live import means we must wait, do NOTHING —
        // keep the dirty-key queue and do not stamp the watermark, so the
        // deferred change set is picked up by the next run and "last detection"
        // never claims a scan that did not happen.
        if (($reason = $this->detector->pendingImportBlock($tenantId)) !== null) {
            Log::warning("[detect] tenant {$tenantId}: run deferred ({$mode}) — {$reason}; dirty keys and watermark kept.");

            return;
        }

        if ($mode === 'aggregate') {
            $this->detector->runForTenant($tenantId, null, true);
            $this->correlator->correlateForTenant($tenantId);

            return;
        }

        if ($mode !== 'incremental') {
            // Full scan.
            $this->detector->runForTenant($tenantId);
            $this->correlator->correlateForTenant($tenantId);
            DetectionDirtyKey::where('tenant_id', $tenantId)->delete();
            $this->stampWatermark($tenantId);

            return;
        }

        $scope = RunScope::forTenant($tenantId, (int) config('detection.max_union_skus', 20000));

        if ($scope === null) {
            // Change set too broad — a full scan is cheaper. Clear the whole queue.
            $this->detector->runForTenant($tenantId);
            $this->correlator->correlateForTenant($tenantId);
            DetectionDirtyKey::where('tenant_id', $tenantId)->delete();
            $this->stampWatermark($tenantId);

            return;
        }

        if ($scope->isEmpty()) {
            // Nothing changed and nothing open — skip the scan, just advance the watermark.
            $this->stampWatermark($tenantId);

            return;
        }

        $this->detector->runForTenant($tenantId, $scope);
        $this->correlator->correlateForTenant($tenantId);

        // Consume only the keys folded into this run (concurrent inserts during
        // the run keep a higher id and survive for the next run).
        DetectionDirtyKey::where('tenant_id', $tenantId)
            ->where('id', '<=', $scope->maxDirtyId())
            ->delete();
        $this->stampWatermark($tenantId);
    }

    /** Query-builder update bypasses model events (mirrors the auth-listener pattern). */
    private function stampWatermark(int $tenantId): void
    {
        Tenant::whereKey($tenantId)->update(['last_detection_at' => now()]);
    }
}
