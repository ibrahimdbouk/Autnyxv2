<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\MappingMemory;
use App\Models\Product;
use App\Services\DataQuality\CleansingDryRunService;
use Tests\TestCase;

/**
 * Slice 5 (learned column-mapping memory + drift) and Slice 6 (cleansing-rule dry-run).
 * See claude/data-quality-firewall.md.
 */
class DataQualityMappingTest extends TestCase
{
    private function importWithMaps(int $tenantId): Import
    {
        $import = Import::create([
            'tenant_id' => $tenantId, 'data_type' => Import::TYPE_SALES,
            'disk' => 'local', 'path' => 'x.csv', 'original_filename' => 'x.csv',
            'status' => Import::STATUS_COMPLETED,
        ]);
        foreach ([['Item Code', 'sku'], ['Txn Date', 'date'], ['Qty', 'quantity']] as $i => [$h, $f]) {
            $import->columnMaps()->create([
                'source_header' => $h, 'target_field' => $f,
                'is_skipped' => false, 'is_confirmed' => true, 'sort_order' => $i,
            ]);
        }

        return $import;
    }

    public function test_mapping_memory_learns_and_recalls_regardless_of_order(): void
    {
        $tenant = $this->createTenant();
        MappingMemory::rememberFromImport($this->importWithMaps($tenant->id));

        $this->assertDatabaseHas('mapping_memories', [
            'tenant_id' => $tenant->id, 'data_type' => Import::TYPE_SALES,
        ]);

        // Same headers, different order → exact recall (learned, confirmed).
        $recall = MappingMemory::recall($tenant->id, Import::TYPE_SALES, ['Qty', 'Item Code', 'Txn Date']);
        $this->assertNotNull($recall);
        $byHeader = collect($recall)->keyBy('source_header');
        $this->assertSame('sku', $byHeader['Item Code']['target_field']);
        $this->assertSame('quantity', $byHeader['Qty']['target_field']);
        $this->assertTrue($byHeader['Item Code']['is_confirmed']);
    }

    public function test_schema_drift_reuses_known_columns_and_flags_new_ones(): void
    {
        $tenant = $this->createTenant();
        MappingMemory::rememberFromImport($this->importWithMaps($tenant->id));

        // A new "Coupon" column appears alongside the known ones (75% overlap).
        $recall = MappingMemory::recall($tenant->id, Import::TYPE_SALES, ['Item Code', 'Txn Date', 'Qty', 'Coupon']);
        $this->assertNotNull($recall, 'drift within overlap threshold still recalls');
        $byHeader = collect($recall)->keyBy('source_header');

        $this->assertSame('sku', $byHeader['Item Code']['target_field'], 'known column stays mapped');
        $this->assertNull($byHeader['Coupon']['target_field'], 'the new column is unmapped');
        $this->assertFalse($byHeader['Coupon']['is_confirmed'], 'and flagged for one-time confirmation');
    }

    public function test_unrelated_headers_recall_nothing(): void
    {
        $tenant = $this->createTenant();
        MappingMemory::rememberFromImport($this->importWithMaps($tenant->id));

        $this->assertNull(MappingMemory::recall($tenant->id, Import::TYPE_SALES, ['Alpha', 'Beta', 'Gamma']));
    }

    public function test_cleansing_rule_dry_run_previews_changes(): void
    {
        $tenant = $this->createTenant();
        Product::create(['tenant_id' => $tenant->id, 'sku' => 'abc-1', 'name' => 'Widget']);

        $r = app(CleansingDryRunService::class)->preview($tenant->id, 'products', 'sku', 'upper', []);

        $this->assertTrue($r['supported']);
        $this->assertGreaterThanOrEqual(1, $r['changed']);
        $this->assertSame('ABC-1', $r['samples'][0]['after']);
    }
}
