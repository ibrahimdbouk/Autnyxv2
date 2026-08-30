<?php

namespace Tests\Feature;

use App\Models\DetectionDirtyKey;
use App\Models\InventoryLevel;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Detection\DirtyKeyRecorder;
use Tests\TestCase;

/**
 * Incremental detection — Slice 4/5. Aggregate mode runs only the complement of
 * the incremental allowlist; full mode clears the dirty queue; the shadow-diff
 * command validates parity without writing.
 */
class DetectionModesTest extends TestCase
{
    private function seedNegative(): array
    {
        $t     = $this->createTenant();
        $store = Store::create(['tenant_id' => $t->id, 'name' => 'S1', 'code' => 'ST1']);
        InventoryLevel::factory()->create([
            'tenant_id' => $t->id, 'store_id' => $store->id, 'sku' => 'NEG',
            'on_hand_qty' => -5, 'as_of_date' => now()->toDateString(),
        ]);
        app(DirtyKeyRecorder::class)->record($t->id, [['store_id' => $store->id, 'sku' => 'NEG']]);

        return [$t, $store];
    }

    public function test_aggregate_mode_skips_allowlist_rules(): void
    {
        [$t] = $this->seedNegative();

        // negative_inventory is an allowlist (per-key) rule → aggregate mode must skip it.
        app(AnomalyDetectionService::class)->runForTenant($t->id, null, true);

        $this->assertDatabaseMissing('anomalies', [
            'tenant_id' => $t->id, 'rule_type' => 'negative_inventory', 'sku' => 'NEG',
        ]);
    }

    public function test_full_mode_clears_the_dirty_queue(): void
    {
        [$t] = $this->seedNegative();
        $this->assertSame(1, DetectionDirtyKey::where('tenant_id', $t->id)->count());

        $this->artisan('anomalies:detect', ['--tenant' => $t->id, '--mode' => 'full'])
            ->assertSuccessful();

        $this->assertSame(0, DetectionDirtyKey::where('tenant_id', $t->id)->count(),
            'a full run clears the queue so it cannot grow unbounded');
        $this->assertNotNull($t->fresh()->last_detection_at);
        // full mode still detects (unlike aggregate)
        $this->assertDatabaseHas('anomalies', ['tenant_id' => $t->id, 'rule_type' => 'negative_inventory', 'sku' => 'NEG']);
    }

    public function test_shadow_diff_reports_parity_and_writes_nothing(): void
    {
        [$t] = $this->seedNegative();

        $this->artisan('detection:shadow-diff', ['--tenant' => $t->id])
            ->assertSuccessful();

        // Both runs are rolled back — nothing persisted.
        $this->assertDatabaseMissing('anomalies', ['tenant_id' => $t->id]);
        // And the queue is untouched by a read-only diff.
        $this->assertSame(1, DetectionDirtyKey::where('tenant_id', $t->id)->count());
    }
}
