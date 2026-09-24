<?php

namespace Tests\Feature;

use App\Filament\Resources\ImportResource\Pages\ReviewMapping;
use App\Models\Import;
use App\Models\MappingMemory;
use App\Models\SalesTransaction;
use App\Models\Tenant;
use App\Services\Import\CanonicalSchema;
use App\Services\Import\ColumnMappingService;
use App\Services\Integrations\PipelineIngestor;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WP3.3 (audit H24) — mappings are conservative, unique per field, gated for
 * unattended imports, and only human-confirmed clean imports teach the memory.
 */
class MappingEngineTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => null]);
        $this->tenant = $this->createTenant();
    }

    private function mapOf(array $headers, string $type): array
    {
        return collect(app(ColumnMappingService::class)->map($headers, [], $type))
            ->mapWithKeys(fn ($m) => [$m['source_header'] => $m])->all();
    }

    public function test_the_audit_mismatches_no_longer_happen(): void
    {
        $sales = $this->mapOf(['Channel', 'Unit Cost'], Import::TYPE_SALES);
        $this->assertNotSame('location', $sales['Channel']['target_field']);
        $this->assertNotSame('sku', $sales['Unit Cost']['target_field']);

        $stores = $this->mapOf(['Latitude'], Import::TYPE_STORES);
        $this->assertNull($stores['Latitude']['target_field']);

        $inv = $this->mapOf(['Safety Stock', 'Expiry Date'], Import::TYPE_INVENTORY);
        $this->assertNotSame('reorder_point', $inv['Safety Stock']['target_field']);
        $this->assertNotSame('as_of_date', $inv['Expiry Date']['target_field']);
    }

    public function test_clear_headers_still_map(): void
    {
        $m = $this->mapOf(['Txn Date', 'Item Code', 'Qty', 'Store Name', 'Sale Date Typo'], Import::TYPE_SALES);
        $this->assertSame('date', $m['Txn Date']['target_field']);
        $this->assertSame('sku', $m['Item Code']['target_field']);
        $this->assertSame('quantity', $m['Qty']['target_field']);
        $this->assertSame('location', $m['Store Name']['target_field']);
    }

    public function test_a_field_is_never_claimed_twice_and_the_conflict_goes_to_review(): void
    {
        $m = $this->mapOf(['Quantity', 'Qty', 'Date', 'SKU'], Import::TYPE_SALES);
        $this->assertSame('quantity', $m['Quantity']['target_field'], 'the exact header wins');
        $this->assertNull($m['Qty']['target_field']);
        $this->assertStringStartsWith('Conflict', $m['Qty']['reasoning']);

        $svc = app(ColumnMappingService::class);
        $this->assertStringContainsString('Qty', (string) $svc->reviewReason(array_values($m), Import::TYPE_SALES));
        $this->assertStringContainsString('Quantity', (string) $svc->reviewReason([
            ['source_header' => 'Date', 'target_field' => 'date', 'confidence' => 1, 'reasoning' => ''],
            ['source_header' => 'SKU', 'target_field' => 'sku', 'confidence' => 1, 'reasoning' => ''],
        ], Import::TYPE_SALES), 'a missing required field needs review');
        $this->assertNull($svc->reviewReason([
            ['source_header' => 'Date', 'target_field' => 'date', 'confidence' => 1, 'reasoning' => ''],
            ['source_header' => 'SKU', 'target_field' => 'sku', 'confidence' => 1, 'reasoning' => ''],
            ['source_header' => 'Qty', 'target_field' => 'quantity', 'confidence' => 1, 'reasoning' => ''],
            ['source_header' => 'Notes', 'target_field' => null, 'confidence' => 0, 'reasoning' => ''],
        ], Import::TYPE_SALES));
    }

    public function test_ai_mapping_is_whitelisted_thresholded_unique_and_sees_no_reference_values(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        $answer = json_encode([
            ['source_header' => 'D', 'target_field' => 'date', 'confidence' => 0.97, 'reasoning' => 'dates'],
            ['source_header' => 'S', 'target_field' => 'sku', 'confidence' => 0.95, 'reasoning' => 'codes'],
            ['source_header' => 'Q', 'target_field' => 'quantity', 'confidence' => 0.9, 'reasoning' => 'qty'],
            ['source_header' => 'Q2', 'target_field' => 'quantity', 'confidence' => 0.9, 'reasoning' => 'also qty'],
            ['source_header' => 'X', 'target_field' => 'is_tenant_admin', 'confidence' => 0.99, 'reasoning' => 'nope'],
            ['source_header' => 'P', 'target_field' => 'unit_price', 'confidence' => 0.6, 'reasoning' => 'maybe'],
        ]);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => "```json\n{$answer}\n```"]], 'stop_reason' => 'end_turn'])]);

        $headers = ['D', 'S', 'Q', 'Q2', 'X', 'P', 'Customer Ref'];
        $m = collect(app(ColumnMappingService::class)->map($headers, [['Customer Ref' => 'CUST-SECRET-42', 'S' => 'A1']], Import::TYPE_SALES))
            ->keyBy('source_header');

        $this->assertSame('date', $m['D']['target_field']);
        $this->assertNull($m['X']['target_field'], 'a field outside the schema is dropped');
        $this->assertNull($m['P']['target_field'], 'below 0.75 is not applied');
        $this->assertNull($m['Q']['target_field']);
        $this->assertNull($m['Q2']['target_field'], 'an equal-confidence tie leaves both for review');

        Http::assertSent(function (Request $r) use ($headers) {
            $body = $r->data();

            return $body['max_tokens'] >= 256 + 96 * count($headers)
                && ! str_contains(json_encode($body), 'CUST-SECRET-42');
        });
    }

    private function confirmedImport(array $maps, array $attrs = []): Import
    {
        $import = Import::create(array_merge([
            'tenant_id' => $this->tenant->id, 'data_type' => Import::TYPE_SALES, 'disk' => 'local', 'path' => 'x.csv',
            'original_filename' => 'x.csv', 'status' => Import::STATUS_COMPLETED, 'imported_rows' => 100,
            'mapping_confirmed_at' => now(),
        ], $attrs));
        foreach ($maps as $i => [$h, $f]) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $f, 'is_skipped' => $f === null, 'is_confirmed' => true, 'sort_order' => $i]);
        }

        return $import;
    }

    public function test_only_person_confirmed_clean_imports_teach_the_memory(): void
    {
        $maps = [['Item Code', 'sku'], ['Txn Date', 'date'], ['Qty', 'quantity'], ['Notes', null]];

        MappingMemory::rememberFromImport($this->confirmedImport($maps, ['mapping_confirmed_at' => null]));
        MappingMemory::rememberFromImport($this->confirmedImport($maps, ['imported_rows' => 50, 'failed_rows' => 50]));
        $this->assertSame(0, MappingMemory::count(), 'auto-accepted or mostly-failing imports teach nothing');

        MappingMemory::rememberFromImport($this->confirmedImport($maps));
        $this->assertSame(CanonicalSchema::VERSION, MappingMemory::value('schema_version'));

        // The skipped "Notes" column is part of the signature → exact recall of the full file.
        $recall = collect(MappingMemory::recall($this->tenant->id, Import::TYPE_SALES, ['Notes', 'Qty', 'Item Code', 'Txn Date']))->keyBy('source_header');
        $this->assertSame('sku', $recall['Item Code']['target_field']);
        $this->assertNull($recall['Notes']['target_field']);
        $this->assertTrue($recall['Notes']['is_confirmed'], 'a confirmed skip is not re-asked');
    }

    public function test_memories_from_an_older_schema_are_ignored(): void
    {
        MappingMemory::create([
            'tenant_id' => $this->tenant->id, 'data_type' => Import::TYPE_SALES,
            'signature' => MappingMemory::signature(['Channel', 'Qty']), 'header_count' => 2, 'schema_version' => 0,
            'mappings' => [['source_header' => 'Channel', 'target_field' => 'location'], ['source_header' => 'Qty', 'target_field' => 'quantity']],
        ]);

        $this->assertNull(MappingMemory::recall($this->tenant->id, Import::TYPE_SALES, ['Channel', 'Qty']));
    }

    public function test_an_unattended_import_with_an_uncertain_mapping_waits_for_a_person(): void
    {
        $admin = $this->createUser($this->tenant, admin: true);

        // No column maps to the required SKU → held, admins told, nothing written.
        $held = app(PipelineIngestor::class)->ingestRows($this->tenant->id, Import::TYPE_SALES, [
            ['date' => '2026-04-03', 'article_ref_code' => 'A1', 'quantity' => 1],
        ]);
        $this->assertSame(Import::STATUS_MAPPING_REVIEW, $held->status);
        $this->assertStringContainsString('SKU', (string) $held->error_message);
        $this->assertSame(0, SalesTransaction::count());
        $this->assertSame(1, $admin->notifications()->count());

        // Canonical keys → certain → processed.
        $ok = app(PipelineIngestor::class)->ingestRows($this->tenant->id, Import::TYPE_SALES, [
            ['date' => '2026-04-03', 'sku' => 'A1', 'quantity' => 1],
        ]);
        $this->assertNotSame(Import::STATUS_MAPPING_REVIEW, $ok->status);
        $this->assertSame(1, SalesTransaction::count());
    }

    public function test_review_rejects_a_field_mapped_twice_and_records_who_confirmed(): void
    {
        $admin = $this->createUser($this->tenant, admin: true);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('x.csv', "D,S,Q\n");

        $import = Import::create([
            'tenant_id' => $this->tenant->id, 'data_type' => Import::TYPE_SALES, 'disk' => 'local', 'path' => 'x.csv',
            'original_filename' => 'x.csv', 'status' => Import::STATUS_MAPPING_REVIEW,
        ]);
        $ids = [];
        foreach (['D' => 'date', 'S' => 'sku', 'Q' => 'quantity'] as $h => $f) {
            $ids[$h] = $import->columnMaps()->create(['source_header' => $h, 'target_field' => $f, 'is_skipped' => false])->id;
        }
        $state = fn (string $qTarget) => [
            ['id' => $ids['D'], 'source_header' => 'D', 'target_field' => 'date', 'is_skipped' => false, 'confidence' => 1.0, 'reasoning' => ''],
            ['id' => $ids['S'], 'source_header' => 'S', 'target_field' => 'sku', 'is_skipped' => false, 'confidence' => 1.0, 'reasoning' => ''],
            ['id' => $ids['Q'], 'source_header' => 'Q', 'target_field' => $qTarget, 'is_skipped' => false, 'confidence' => 1.0, 'reasoning' => ''],
        ];

        Livewire::test(ReviewMapping::class, ['record' => $import])->set('mappings', $state('sku'))->call('confirmAndImport');
        $this->assertNull($import->fresh()->mapping_confirmed_at, 'SKU twice is refused');
        $this->assertSame(Import::STATUS_MAPPING_REVIEW, $import->fresh()->status);

        Livewire::test(ReviewMapping::class, ['record' => $import])->set('mappings', $state('quantity'))->call('confirmAndImport');
        $this->assertSame($admin->id, (int) $import->fresh()->mapping_confirmed_by);
        $this->assertSame(Import::STATUS_IMPORTING, $import->fresh()->status);
    }
}
