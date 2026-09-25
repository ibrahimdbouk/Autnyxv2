<?php

namespace App\Services\Detection;

use App\Models\DetectionDirtyKey;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Anomaly\InvestigationCorrelationService;
use Illuminate\Support\Facades\DB;
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
    public function run(int $tenantId, ?string $mode = null, int $waitSeconds = 0): void
    {
        $mode = $mode ?: config('detection.mode', 'full');

        // WP5.2 (audit H3): one detection writer per tenant — nightly, import,
        // manual and recalibration runs share this lock (database cache store).
        $lock = \App\Services\Pipeline\TenantDetectionLock::for($tenantId);
        $got  = $waitSeconds > 0 ? $this->blockFor($lock, $waitSeconds) : $lock->get();
        if (! $got) {
            throw new \App\Services\Pipeline\DetectionBusy("Detection is already running for tenant {$tenantId}.");
        }
        try {
            $this->runLocked($tenantId, $mode);
        } finally {
            $lock->release();
        }
    }

    /** @var array<string,array{ms:int,peak_mb:float,flags:int,ok:bool}> WP6.3: last full run, summed over its passes */
    private array $stats = [];

    /** @return array<string,int> W10: flags a promotion explained, in the last run */
    public function lastPromoSuppressed(): array
    {
        return $this->detector->promoSuppressedByRule();
    }

    /** WP6.3: per-rule time / memory / flags of this runner's last detection run. */
    public function lastRuleStats(): array
    {
        return $this->stats ?: $this->detector->ruleStats();
    }

    /**
     * WP6.3 (audit H31) — a full scan. A v2 tenant with more positions than
     * detection.bucket_positions is scanned in SKU buckets: each bucket runs
     * the per-SKU rules with every store of its SKUs loaded (so memory is
     * bounded by the bucket, not the tenant), then one aggregate pass runs the
     * tenant-wide rules, which work in SQL. Smaller tenants: one pass, as before.
     */
    public function runFull(int $tenantId): void
    {
        $this->stats = [];
        $buckets = $this->buckets($tenantId);
        if ($buckets === null) {
            $this->detector->runForTenant($tenantId);
            $this->stats = $this->detector->ruleStats();

            return;
        }

        foreach ($buckets as $skus) {
            $this->detector->runForTenant($tenantId, RunScope::ofSkus($skus), false, true);
            $this->addStats($this->detector->ruleStats());
            if ($this->detector->lastRunDeferred) {
                return;
            }
        }
        $this->detector->runForTenant($tenantId, null, true, true);
        $this->addStats($this->detector->ruleStats());
        Log::info('[detect] bucketed full run', ['tenant_id' => $tenantId, 'buckets' => count($buckets)]);
    }

    /** @return array<int,array<int,string>>|null SKU buckets, or null for a single pass */
    public function buckets(int $tenantId): ?array
    {
        if (! AnomalyDetectionService::rulesV2For($tenantId)) {
            return null;
        }
        $limit = max(1, (int) config('detection.bucket_positions', 250000));
        $positions = (int) DB::table('inventory_current')->where('tenant_id', $tenantId)->count();
        $stores = max(1, (int) DB::table('stores')->where('tenant_id', $tenantId)->count());
        $positions = max($positions, (int) DB::table('sku_profiles')->where('tenant_id', $tenantId)->where('store_id', '>', 0)->count());
        if ($positions <= $limit) {
            return null;
        }

        // Every SKU a per-SKU rule could judge: the master, profiled sales
        // (90 days), stock positions, and anything still open.
        $skus = collect(DB::select(
            "SELECT s FROM (
                SELECT trim(sku) AS s FROM products WHERE tenant_id = ?
                UNION SELECT trim(sku) FROM sku_profiles WHERE tenant_id = ? AND store_id = 0
                UNION SELECT trim(sku) FROM inventory_current WHERE tenant_id = ?
                UNION SELECT trim(sku) FROM anomalies WHERE tenant_id = ? AND dismissed_at IS NULL AND lifecycle_state <> 'resolved'
             ) u WHERE s IS NOT NULL AND s <> '' ORDER BY s",
            [$tenantId, $tenantId, $tenantId, $tenantId]
        ))->pluck('s');

        $per = max(1, intdiv($limit, $stores));

        return $skus->chunk($per)->map(fn ($c) => $c->values()->all())->values()->all();
    }

    private function addStats(array $pass): void
    {
        foreach ($pass as $rule => $s) {
            $cur = $this->stats[$rule] ?? ['ms' => 0, 'peak_mb' => 0.0, 'flags' => 0, 'ok' => true];
            $this->stats[$rule] = ['ms' => $cur['ms'] + $s['ms'], 'peak_mb' => max($cur['peak_mb'], $s['peak_mb']),
                'flags' => $cur['flags'] + $s['flags'], 'ok' => $cur['ok'] && $s['ok']];
        }
    }

    private function blockFor($lock, int $seconds): bool
    {
        try {
            return (bool) $lock->block($seconds);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return false;
        }
    }

    private function runLocked(int $tenantId, string $mode): void
    {
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

        // WP5.2: only the dirty keys that existed when the scan STARTED are
        // consumed — a key an import adds mid-run survives for the next run.
        $maxDirty = (int) DetectionDirtyKey::where('tenant_id', $tenantId)->max('id');

        if ($mode !== 'incremental') {
            // Full scan (WP6.3: split by SKU bucket for a big tenant).
            $this->runFull($tenantId);
            $this->correlator->correlateForTenant($tenantId);
            $this->consume($tenantId, $maxDirty);
            $this->stampWatermark($tenantId);

            return;
        }

        $scope = RunScope::forTenant($tenantId, (int) config('detection.max_union_skus', 20000));

        if ($scope === null) {
            // Change set too broad — a full scan is cheaper. Clear what it covered.
            $this->runFull($tenantId);
            $this->correlator->correlateForTenant($tenantId);
            $this->consume($tenantId, $maxDirty);
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

    private function consume(int $tenantId, int $maxId): void
    {
        if ($maxId > 0) {
            DetectionDirtyKey::where('tenant_id', $tenantId)->where('id', '<=', $maxId)->delete();
        }
    }

    /** Query-builder update bypasses model events (mirrors the auth-listener pattern). */
    private function stampWatermark(int $tenantId): void
    {
        Tenant::whereKey($tenantId)->update(['last_detection_at' => now()]);
    }
}
