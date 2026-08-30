<?php

namespace Tests\Feature;

use App\Models\DetectionDirtyKey;
use App\Models\Import;
use App\Models\InventoryLevel;
use App\Models\Store;
use App\Services\Detection\DirtyKeyRecorder;
use App\Services\Import\ImportProcessorService;
use Tests\TestCase;

/**
 * Incremental detection — Slice 1 (capture). The queue must fill correctly and
 * idempotently; detection behaviour is unchanged.
 */
class DirtyKeyCaptureTest extends TestCase
{
    private function recorder(): DirtyKeyRecorder
    {
        return app(DirtyKeyRecorder::class);
    }

    public function test_records_and_dedupes_subjects(): void
    {
        $t = $this->createTenant();

        $n = $this->recorder()->record($t->id, [
            ['store_id' => 10, 'sku' => 'SKU1'],
            ['store_id' => 10, 'sku' => 'SKU1'], // duplicate in same call
            ['store_id' => 11, 'sku' => 'SKU2'],
        ]);

        $this->assertSame(2, $n, 'in-call duplicates collapse to one row');
        $this->assertSame(2, DetectionDirtyKey::where('tenant_id', $t->id)->count());
    }

    public function test_is_idempotent_across_calls(): void
    {
        $t = $this->createTenant();

        $this->recorder()->record($t->id, [['store_id' => 5, 'sku' => 'A']]);
        $this->recorder()->record($t->id, [['store_id' => 5, 'sku' => 'A']]); // already queued
        $this->recorder()->record($t->id, [['store_id' => 5, 'sku' => 'B']]); // new subject

        $this->assertSame(2, DetectionDirtyKey::where('tenant_id', $t->id)->count(),
            'a subject already queued is not duplicated');
    }

    public function test_skips_fully_null_keys_and_empty_input(): void
    {
        $t = $this->createTenant();

        $this->assertSame(0, $this->recorder()->record($t->id, []));
        $this->assertSame(0, $this->recorder()->record($t->id, [['store_id' => null, 'sku' => null]]));
        $this->assertSame(0, DetectionDirtyKey::where('tenant_id', $t->id)->count());
    }

    public function test_records_the_given_reason(): void
    {
        $t = $this->createTenant();

        $this->recorder()->record($t->id, [['store_id' => 1, 'sku' => 'X']], DetectionDirtyKey::REASON_ROLLBACK);

        $this->assertDatabaseHas('detection_dirty_keys', [
            'tenant_id' => $t->id, 'store_id' => 1, 'sku' => 'X', 'reason' => 'rollback',
        ]);
    }

    public function test_rollback_marks_deleted_subjects_dirty(): void
    {
        $t     = $this->createTenant();
        $store = Store::create(['tenant_id' => $t->id, 'name' => 'Store 1', 'code' => 'ST1']);

        $import = Import::create([
            'tenant_id'         => $t->id,
            'original_filename' => 'inv.csv',
            'path'              => 'inv.csv',
            'data_type'         => Import::TYPE_INVENTORY,
            'status'            => Import::STATUS_COMPLETED,
            'total_rows'        => 3,
        ]);

        InventoryLevel::factory()->create(['tenant_id' => $t->id, 'import_id' => $import->id, 'store_id' => $store->id, 'sku' => 'SKU1']);
        InventoryLevel::factory()->create(['tenant_id' => $t->id, 'import_id' => $import->id, 'store_id' => $store->id, 'sku' => 'SKU1']); // same subject
        InventoryLevel::factory()->create(['tenant_id' => $t->id, 'import_id' => $import->id, 'store_id' => $store->id, 'sku' => 'SKU2']);

        app(ImportProcessorService::class)->rollback($import);

        $keys = DetectionDirtyKey::where('tenant_id', $t->id)->get();
        $this->assertCount(2, $keys, 'two distinct subjects queued from the rolled-back rows');
        $this->assertTrue($keys->every(fn ($k) => $k->reason === DetectionDirtyKey::REASON_ROLLBACK));
        $this->assertEqualsCanonicalizing(['SKU1', 'SKU2'], $keys->pluck('sku')->sort()->values()->all());
    }
}
