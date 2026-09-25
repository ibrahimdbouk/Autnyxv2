<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\InventoryCurrent;
use App\Models\InventoryLevel;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Anomaly\EvidenceCollectorService;
use App\Services\Import\ImportProcessorService;
use App\Services\Inventory\InventoryCurrentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WP6.2 (audit H20) — "how much is on hand" is the newest snapshot of each
 * (store, SKU) with its lots summed, kept in inventory_current; history rows
 * are never counted as stock.
 */
class InventoryCurrentTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        ImportProcessorService::forgetNaturalKeys();
        Storage::fake('local');
        $this->tenant = $this->createTenant();
    }

    private function import(string $csv): Import
    {
        $path = 'imports/pending/' . uniqid() . '.csv';
        Storage::disk('local')->put($path, $csv);
        $import = Import::create([
            'tenant_id' => $this->tenant->id, 'original_filename' => 'f.csv', 'disk' => 'local', 'path' => $path,
            'data_type' => Import::TYPE_INVENTORY, 'status' => Import::STATUS_UPLOADED,
            'total_rows' => substr_count(trim($csv), "\n"), 'date_format' => 'Y-m-d',
        ]);
        foreach (['SKU' => 'sku', 'Store' => 'location', 'OnHand' => 'on_hand_qty', 'AsOf' => 'as_of_date', 'Lot' => 'batch_ref', 'ROP' => 'reorder_point'] as $h => $f) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $f, 'is_skipped' => false, 'is_confirmed' => true]);
        }
        $svc = app(ImportProcessorService::class);
        $svc->startChunkedImport($import);
        $guard = 0;
        do {
            $r = $svc->processChunk($import->fresh(), 100);
        } while (! ($r['done'] ?? false) && ++$guard < 20);

        return $import->fresh();
    }

    private function position(string $sku): ?InventoryCurrent
    {
        return InventoryCurrent::where('tenant_id', $this->tenant->id)->where('sku', $sku)->first();
    }

    public function test_an_import_keeps_the_newest_snapshot_with_its_lots_summed(): void
    {
        $this->import("SKU,Store,OnHand,AsOf,Lot,ROP\nA,Downtown,4,2026-04-01,L1,5\nA,Downtown,6,2026-04-01,L2,5\nB,Downtown,9,2026-04-01,,2\n");
        $this->import("SKU,Store,OnHand,AsOf,Lot,ROP\nA,Downtown,1,2026-04-08,L1,5\nA,Downtown,2,2026-04-08,L3,5\n");

        $a = $this->position('A');
        $this->assertEqualsWithDelta(3, (float) $a->on_hand_qty, 0.001, 'newest day, both lots — not 4+6+1+2');
        $this->assertSame('2026-04-08', $a->as_of_date->toDateString());
        $this->assertSame(2, $a->lots);
        $this->assertEqualsWithDelta(9, (float) $this->position('B')->on_hand_qty, 0.001);
        $this->assertSame(5, InventoryLevel::count(), 'the history is kept');
    }

    public function test_a_late_older_snapshot_never_overwrites_the_current_position(): void
    {
        $this->import("SKU,Store,OnHand,AsOf,Lot,ROP\nA,Downtown,7,2026-04-08,,\n");
        $this->import("SKU,Store,OnHand,AsOf,Lot,ROP\nA,Downtown,50,2026-03-01,,\n");

        $this->assertEqualsWithDelta(7, (float) $this->position('A')->on_hand_qty, 0.001);
    }

    public function test_an_undone_load_falls_back_to_the_previous_snapshot_or_leaves(): void
    {
        $this->import("SKU,Store,OnHand,AsOf,Lot,ROP\nA,Downtown,7,2026-04-01,,\n");
        $second = $this->import("SKU,Store,OnHand,AsOf,Lot,ROP\nA,Downtown,2,2026-04-08,,\nC,Downtown,5,2026-04-08,,\n");

        app(ImportProcessorService::class)->rollback($second);

        $this->assertEqualsWithDelta(7, (float) $this->position('A')->on_hand_qty, 0.001);
        $this->assertNull($this->position('C'), 'a position with no history left is gone');
    }

    public function test_single_row_writes_keep_the_position_in_step(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Mall']);
        $lot = InventoryLevel::create(['tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'X', 'on_hand_qty' => 3, 'as_of_date' => '2026-04-01']);
        InventoryLevel::create(['tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'X', 'on_hand_qty' => 2, 'as_of_date' => '2026-04-01', 'batch_ref' => 'B']);
        $this->assertEqualsWithDelta(5, (float) $this->position('X')->on_hand_qty, 0.001);

        $lot->delete();
        $this->assertEqualsWithDelta(2, (float) $this->position('X')->on_hand_qty, 0.001);
    }

    public function test_positions_are_built_on_first_use_and_rebuild_is_idempotent(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Mall']);
        DB::table('inventory_levels')->insert([
            ['tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'Y', 'on_hand_qty' => 1, 'as_of_date' => '2026-04-01', 'batch_ref' => 'a', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'Y', 'on_hand_qty' => 2, 'as_of_date' => '2026-04-01', 'batch_ref' => 'b', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->assertNull($this->position('Y'));

        app(InventoryCurrentService::class)->ensure($this->tenant->id);
        $this->assertEqualsWithDelta(3, (float) $this->position('Y')->on_hand_qty, 0.001);

        $this->artisan('inventory:rebuild-current', ['--tenant' => $this->tenant->id])->assertSuccessful();
        $this->artisan('inventory:rebuild-current', ['--tenant' => $this->tenant->id])->assertSuccessful();
        $this->assertSame(1, InventoryCurrent::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_the_what_if_on_hand_evidence_is_the_whole_position(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Mall']);
        foreach (['a' => 4, 'b' => 5] as $lot => $qty) {
            InventoryLevel::create(['tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'Z', 'on_hand_qty' => $qty,
                'reorder_point' => 10, 'as_of_date' => now()->toDateString(), 'batch_ref' => $lot]);
        }
        $anomaly = \App\Models\Anomaly::create(['tenant_id' => $this->tenant->id, 'rule_type' => 'stockout_risk', 'severity' => 'high',
            'sku' => 'Z', 'store_id' => $store->id, 'description' => 'x', 'context' => [], 'detected_at' => now()]);
        $inv = \App\Models\Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'primary_sku' => 'Z']);
        $anomaly->update(['investigation_id' => $inv->id]);

        app(EvidenceCollectorService::class)->collectForInvestigation($inv->fresh());

        $onHand = $inv->evidence()->where('label', 'Current on-hand quantity')->value('value_numeric');
        $this->assertEqualsWithDelta(9, (float) $onHand, 0.001, 'both lots, not whichever row came first');
    }
}
