<?php

namespace Tests\Feature;

use App\Jobs\RunTenantDetectionJob;
use App\Models\DetectionDirtyKey;
use App\Models\Import;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Detection\DirtyKeyRecorder;
use App\Services\Detection\TenantDetectionRunner;
use App\Services\Import\ImportProcessorService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WP1.2 — audit C3 (an abandoned upload silently switched detection off for its
 * tenant forever, while the runner still discarded the change set and stamped
 * the watermark) and audit H1 (commit 2943c5c reverted async detection).
 */
class DetectionUnblockTest extends TestCase
{
    private function import(int $tenantId, string $status, \DateTimeInterface $at): Import
    {
        $import = Import::create([
            'tenant_id' => $tenantId, 'original_filename' => 'sales.csv', 'disk' => 'local',
            'path' => 'imports/x.csv', 'data_type' => Import::TYPE_SALES, 'status' => $status, 'total_rows' => 1,
        ]);
        Import::whereKey($import->id)->update(['created_at' => $at, 'updated_at' => $at]);

        return $import->fresh();
    }

    public function test_abandoned_upload_no_longer_blocks_detection(): void
    {
        $tenant = $this->createTenant();
        $this->import($tenant->id, Import::STATUS_MAPPING_REVIEW, now()->subDays(3));

        $detector = app(AnomalyDetectionService::class);
        $this->assertNull($detector->pendingImportBlock($tenant->id));

        app(TenantDetectionRunner::class)->run($tenant->id, 'full');
        $this->assertNotNull($tenant->fresh()->last_detection_at);
    }

    public function test_stale_importing_row_does_not_block(): void
    {
        $tenant = $this->createTenant();
        $this->import($tenant->id, Import::STATUS_IMPORTING, now()->subHours(2));

        $this->assertNull(app(AnomalyDetectionService::class)->pendingImportBlock($tenant->id));
    }

    public function test_live_upload_defers_and_keeps_dirty_keys_and_watermark(): void
    {
        $tenant = $this->createTenant();
        $this->import($tenant->id, Import::STATUS_MAPPING_REVIEW, now()->subHour());

        app(DirtyKeyRecorder::class)->record($tenant->id, [['store_id' => null, 'sku' => 'SKU-1']], DetectionDirtyKey::REASON_IMPORT);

        foreach (['full', 'incremental', 'aggregate'] as $mode) {
            app(TenantDetectionRunner::class)->run($tenant->id, $mode);
        }

        $this->assertSame(1, DetectionDirtyKey::where('tenant_id', $tenant->id)->count(), 'a deferred run must not discard the change set');
        $this->assertNull($tenant->fresh()->last_detection_at, 'a deferred run must not claim a scan happened');
    }

    public function test_live_importing_row_defers(): void
    {
        $tenant = $this->createTenant();
        $this->import($tenant->id, Import::STATUS_IMPORTING, now()->subMinutes(5));

        $detector = app(AnomalyDetectionService::class);
        $this->assertNotNull($detector->pendingImportBlock($tenant->id));

        $detector->runForTenant($tenant->id, null, true); // aggregate mode is guarded too
        $this->assertTrue($detector->lastRunDeferred);
    }

    public function test_expire_command_retires_stale_uploads_only(): void
    {
        $tenant = $this->createTenant();
        $stale  = $this->import($tenant->id, Import::STATUS_MAPPING_REVIEW, now()->subDays(2));
        $fresh  = $this->import($tenant->id, Import::STATUS_UPLOADED, now()->subHour());
        $done   = $this->import($tenant->id, Import::STATUS_COMPLETED, now()->subDays(9));

        $this->artisan('imports:expire-abandoned')->assertSuccessful();

        $this->assertSame(Import::STATUS_ABANDONED, $stale->fresh()->status);
        $this->assertSame(Import::STATUS_UPLOADED, $fresh->fresh()->status);
        $this->assertSame(Import::STATUS_COMPLETED, $done->fresh()->status);
    }

    public function test_chunked_import_dispatches_incremental_detection_after_commit(): void
    {
        Bus::fake([RunTenantDetectionJob::class]);
        Storage::fake('local');
        $tenant = $this->createTenant();

        Storage::disk('local')->put('imports/pending/r.csv', "Date,SKU,Store,Qty,Value,Reason\n2026-08-01,SKU-1,Downtown,1,1.50,Defective\n");
        $import = Import::create([
            'tenant_id' => $tenant->id, 'original_filename' => 'r.csv', 'disk' => 'local',
            'path' => 'imports/pending/r.csv', 'data_type' => Import::TYPE_RETURNS,
            'status' => Import::STATUS_UPLOADED, 'total_rows' => 1,
        ]);
        foreach (['Date' => 'date', 'SKU' => 'sku', 'Store' => 'location', 'Qty' => 'quantity', 'Value' => 'value', 'Reason' => 'reason'] as $h => $f) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $f, 'is_skipped' => false, 'is_confirmed' => true]);
        }

        $svc = app(ImportProcessorService::class);
        $svc->startChunkedImport($import);
        $guard = 0;
        do {
            $r = $svc->processChunk($import->fresh(), 100);
        } while (! ($r['done'] ?? false) && ++$guard < 10);

        Bus::assertDispatched(RunTenantDetectionJob::class, fn (RunTenantDetectionJob $job) => $job->tenantId === $tenant->id
            && $job->mode === config('detection.import_trigger_mode')
            && $job->afterCommit === true);
    }

    public function test_legacy_process_path_queues_detection_instead_of_running_inline(): void
    {
        Bus::fake([RunTenantDetectionJob::class]);
        Storage::fake('local');
        $tenant = $this->createTenant();

        Storage::disk('local')->put('imports/pending/r2.csv', "Date,SKU,Store,Qty,Value,Reason\n2026-08-01,SKU-1,Downtown,1,1.50,Defective\n");
        $import = Import::create([
            'tenant_id' => $tenant->id, 'original_filename' => 'r2.csv', 'disk' => 'local',
            'path' => 'imports/pending/r2.csv', 'data_type' => Import::TYPE_RETURNS,
            'status' => Import::STATUS_UPLOADED, 'total_rows' => 1,
        ]);
        foreach (['Date' => 'date', 'SKU' => 'sku', 'Store' => 'location', 'Qty' => 'quantity', 'Value' => 'value', 'Reason' => 'reason'] as $h => $f) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $f, 'is_skipped' => false, 'is_confirmed' => true]);
        }

        app(ImportProcessorService::class)->process($import);

        $this->assertTrue($import->fresh()->isCompleted(), 'status: ' . $import->fresh()->status);
        Bus::assertDispatched(RunTenantDetectionJob::class, fn (RunTenantDetectionJob $job) => $job->tenantId === $tenant->id && $job->afterCommit === true);
    }
}
