<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\ImportQuality;
use App\Models\Product;
use App\Services\DataQuality\BatchDecisionService;
use App\Services\DataQuality\DataQualityFirewall;
use App\Services\DataQuality\DataReadinessService;
use Tests\TestCase;

/**
 * Phase 2 — the autonomy envelope: batch GREEN/AMBER/RED decisions, the granular
 * detection-readiness contract (a blocked dataset disables only its own rules), and
 * idempotent duplicate-upload protection. See claude/data-quality-firewall.md.
 */
class DataQualityPhase2Test extends TestCase
{
    public function test_batch_decision_states(): void
    {
        $svc = app(BatchDecisionService::class);

        $this->assertSame(ImportQuality::STATE_GREEN, $svc->decide('sales_transactions', 100, 0, false)['state']);
        $this->assertSame(ImportQuality::STATE_AMBER, $svc->decide('sales_transactions', 92, 8, false)['state']);   // 92%
        $this->assertSame(ImportQuality::STATE_RED,   $svc->decide('sales_transactions', 80, 20, false)['state']);  // 80%

        $dup = $svc->decide('sales_transactions', 100, 0, true);
        $this->assertSame(ImportQuality::STATE_RED, $dup['state']);
        $this->assertTrue($dup['blocked']);
    }

    public function test_readiness_blocks_only_the_blocked_datasets_rules(): void
    {
        $tenant = $this->createTenant();

        $inv = Import::create(['tenant_id' => $tenant->id, 'data_type' => Import::TYPE_INVENTORY,
            'disk' => 'local', 'path' => 'x.csv', 'original_filename' => 'x.csv', 'status' => Import::STATUS_COMPLETED]);
        ImportQuality::create(['tenant_id' => $tenant->id, 'import_id' => $inv->id, 'data_type' => Import::TYPE_INVENTORY,
            'rows_seen' => 100, 'rows_promoted' => 40, 'rows_quarantined' => 60,
            'state' => ImportQuality::STATE_RED, 'blocked' => true]);

        $blocked = app(DataReadinessService::class)->blockedRules($tenant->id);

        $this->assertArrayHasKey('stockout_risk', $blocked, 'an inventory rule is blocked');
        $this->assertArrayHasKey('overstock', $blocked);
        $this->assertArrayNotHasKey('sales_spike', $blocked, 'a sales rule is unaffected');
        $this->assertArrayNotHasKey('price_anomaly', $blocked, 'an ungated rule is always ready');
    }

    public function test_readiness_uses_the_latest_batch_per_dataset(): void
    {
        $tenant = $this->createTenant();

        $red = Import::create(['tenant_id' => $tenant->id, 'data_type' => Import::TYPE_INVENTORY,
            'disk' => 'local', 'path' => 'a.csv', 'original_filename' => 'a.csv', 'status' => Import::STATUS_COMPLETED]);
        $r = ImportQuality::create(['tenant_id' => $tenant->id, 'import_id' => $red->id, 'data_type' => Import::TYPE_INVENTORY,
            'state' => ImportQuality::STATE_RED, 'blocked' => true]);
        $r->created_at = now()->subMinutes(10);
        $r->save();

        $green = Import::create(['tenant_id' => $tenant->id, 'data_type' => Import::TYPE_INVENTORY,
            'disk' => 'local', 'path' => 'b.csv', 'original_filename' => 'b.csv', 'status' => Import::STATUS_COMPLETED]);
        ImportQuality::create(['tenant_id' => $tenant->id, 'import_id' => $green->id, 'data_type' => Import::TYPE_INVENTORY,
            'state' => ImportQuality::STATE_GREEN, 'blocked' => false]);

        // The newer GREEN batch wins → inventory is ready again.
        $this->assertSame([], app(DataReadinessService::class)->blockedRules($tenant->id));
        $this->assertTrue(app(DataReadinessService::class)->isDatasetReady($tenant->id, Import::TYPE_INVENTORY));
    }

    public function test_firewall_records_a_batch_state(): void
    {
        $tenant = $this->createTenant();
        Product::create(['tenant_id' => $tenant->id, 'sku' => 'SKU-1', 'name' => 'Widget']);
        $import = Import::create(['tenant_id' => $tenant->id, 'data_type' => Import::TYPE_SALES,
            'disk' => 'local', 'path' => 'none.csv', 'original_filename' => 'none.csv', 'status' => Import::STATUS_IMPORTING]);

        $fw = app(DataQualityFirewall::class);
        $fw->begin($import);
        $fw->screen($import, ['sku' => 'SKU-1', 'date' => '2026-01-01', 'quantity' => '5']); // clean
        $fw->recordChunk($import);

        $q = ImportQuality::where('import_id', $import->id)->first();
        $this->assertSame(ImportQuality::STATE_GREEN, $q->state, '100% clean → GREEN');
        $this->assertSame('file', $q->source);
    }

    public function test_idempotent_duplicate_flag_gates_the_batch(): void
    {
        $tenant = $this->createTenant();
        $import = Import::create(['tenant_id' => $tenant->id, 'data_type' => Import::TYPE_INVENTORY,
            'disk' => 'local', 'path' => 'dup.csv', 'original_filename' => 'dup.csv', 'status' => Import::STATUS_IMPORTING]);
        // Pre-existing quality row flagged as a duplicate upload.
        ImportQuality::create(['tenant_id' => $tenant->id, 'import_id' => $import->id, 'data_type' => Import::TYPE_INVENTORY,
            'is_duplicate_file' => true]);

        config(['data_quality.idempotent_uploads' => true]);
        $fw = app(DataQualityFirewall::class);
        $fw->begin($import);
        $this->assertTrue($fw->isDuplicateBatch(), 'duplicate upload is skipped when idempotency is on');

        // Off → not treated as a skip.
        config(['data_quality.idempotent_uploads' => false]);
        $fw2 = app(DataQualityFirewall::class);
        $fw2->begin($import->fresh());
        $this->assertFalse($fw2->isDuplicateBatch());
    }
}
