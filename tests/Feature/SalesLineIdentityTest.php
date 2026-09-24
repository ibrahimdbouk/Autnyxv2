<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\SalesTransaction;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Import\ColumnMappingService;
use App\Services\Import\ImportProcessorService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WP3.1 (audit C2) — a sales row is a receipt LINE.
 *
 * Before: UNIQUE (tenant, transaction_id) kept one line per receipt. The bulk
 * path failed the other lines; the per-row and retry paths overwrote them.
 */
class SalesLineIdentityTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        Storage::fake('local');
    }

    private function salesImport(string $csv, array $map = []): Import
    {
        $path = 'imports/pending/sales-' . uniqid() . '.csv';
        Storage::disk('local')->put($path, $csv);

        $import = Import::create([
            'tenant_id' => $this->tenant->id, 'original_filename' => 'sales.csv', 'disk' => 'local',
            'path' => $path, 'data_type' => Import::TYPE_SALES, 'status' => Import::STATUS_UPLOADED,
            'total_rows' => substr_count(trim($csv), "\n"),
        ]);
        foreach (($map ?: ['Date' => 'date', 'Receipt' => 'transaction_id', 'SKU' => 'sku', 'Store' => 'location', 'Qty' => 'quantity']) as $h => $f) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $f, 'is_skipped' => false, 'is_confirmed' => true]);
        }

        return $import;
    }

    private function runChunked(Import $import, int $chunk = 2): Import
    {
        $svc = app(ImportProcessorService::class);
        $svc->startChunkedImport($import);
        $guard = 0;
        do {
            $r = $svc->processChunk($import->fresh(), $chunk);
        } while (! ($r['done'] ?? false) && ++$guard < 50);

        return $import->fresh();
    }

    /** @return array<string, int[]> receipt => line numbers */
    private function lines(): array
    {
        return SalesTransaction::where('tenant_id', $this->tenant->id)->orderBy('transaction_id')->orderBy('line_no')
            ->get()->groupBy('transaction_id')->map(fn ($g) => $g->pluck('line_no')->map(fn ($n) => (int) $n)->all())->all();
    }

    private const RECEIPTS = "Date,Receipt,SKU,Store,Qty\n"
        . "2026-09-01,R1,SKU-A,Downtown,1\n"
        . "2026-09-01,R1,SKU-B,Downtown,2\n"
        . "2026-09-01,R2,SKU-C,Downtown,1\n"
        . "2026-09-01,R1,SKU-A,Downtown,1\n";  // same item scanned again: a real line

    public function test_every_line_of_a_multi_line_receipt_is_kept_across_chunks(): void
    {
        $import = $this->runChunked($this->salesImport(self::RECEIPTS), chunk: 2);

        $this->assertTrue($import->isCompleted(), "status was {$import->status}");
        $this->assertSame(4, (int) $import->imported_rows);
        $this->assertSame(0, (int) $import->failed_rows);
        // R1 spans both chunks and keeps file order: 1, 2 (chunk 1), 3 (chunk 2).
        $this->assertSame(['R1' => [1, 2, 3], 'R2' => [1]], $this->lines());
        $this->assertSame(0, \DB::table('import_line_counters')->where('import_id', $import->id)->count(), 'counters are cleaned up');
    }

    public function test_re_sending_the_same_receipts_skips_them_instead_of_duplicating(): void
    {
        $this->runChunked($this->salesImport(self::RECEIPTS));

        // Next day's extract repeats yesterday's receipts plus one new one.
        $second = $this->runChunked($this->salesImport(self::RECEIPTS . "2026-09-02,R3,SKU-D,Downtown,5\n"), chunk: 3);

        $this->assertSame(1, (int) $second->imported_rows);
        $this->assertSame(4, (int) $second->duplicate_rows);
        $this->assertSame(0, (int) $second->failed_rows);
        $this->assertSame(['R1' => [1, 2, 3], 'R2' => [1], 'R3' => [1]], $this->lines());
    }

    public function test_the_single_pass_path_keeps_every_line_and_never_overwrites(): void
    {
        $import = $this->salesImport(self::RECEIPTS);
        app(ImportProcessorService::class)->process($import);

        $this->assertSame(4, (int) $import->fresh()->imported_rows);
        $this->assertSame(['R1' => [1, 2, 3], 'R2' => [1]], $this->lines());
        $this->assertSame(['SKU-A', 'SKU-B', 'SKU-A'], SalesTransaction::where('transaction_id', 'R1')->orderBy('line_no')->pluck('sku')->all());
    }

    public function test_a_mapped_line_number_is_used_and_a_bad_one_fails_only_its_row(): void
    {
        $csv = "Date,Receipt,Line,SKU,Store,Qty\n"
            . "2026-09-01,R9,10,SKU-A,Downtown,1\n"
            . "2026-09-01,R9,20,SKU-B,Downtown,1\n"
            . "2026-09-01,R9,abc,SKU-C,Downtown,1\n";
        $import = $this->runChunked($this->salesImport($csv, [
            'Date' => 'date', 'Receipt' => 'transaction_id', 'Line' => 'line_no', 'SKU' => 'sku', 'Store' => 'location', 'Qty' => 'quantity',
        ]));

        $this->assertSame(['R9' => [10, 20]], $this->lines());
        $this->assertSame(1, (int) $import->failed_rows);
        $this->assertStringContainsString('line number', ImportRow::where('import_id', $import->id)->value('error_message'));
    }

    public function test_retrying_a_failed_line_inserts_it_as_a_new_line(): void
    {
        $import = $this->salesImport(self::RECEIPTS);
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'transaction_id' => 'R5', 'line_no' => 1, 'sku' => 'SKU-A', 'date' => '2026-09-01', 'quantity' => 1]);
        $row = ImportRow::create([
            'import_id' => $import->id, 'tenant_id' => $this->tenant->id, 'row_number' => 3,
            'raw_data' => ['x' => 1], 'error_message' => 'failed before WP3.1', 'status' => ImportRow::STATUS_PENDING,
            'mapped_data' => ['date' => '2026-09-01', 'transaction_id' => 'R5', 'sku' => 'SKU-B', 'quantity' => '4'],
        ]);

        $result = app(ImportProcessorService::class)->retryRows($import, collect([$row]));

        $this->assertSame(1, $result['retried']);
        $this->assertSame(['SKU-A', 'SKU-B'], SalesTransaction::where('transaction_id', 'R5')->orderBy('line_no')->pluck('sku')->all());
        $this->assertSame([1, 2], SalesTransaction::where('transaction_id', 'R5')->orderBy('line_no')->pluck('line_no')->map(fn ($n) => (int) $n)->all());
    }

    public function test_the_database_rejects_the_same_receipt_line_twice(): void
    {
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'transaction_id' => 'R7', 'line_no' => 1, 'sku' => 'A', 'date' => '2026-09-01', 'quantity' => 1]);
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'transaction_id' => 'R7', 'line_no' => 2, 'sku' => 'A', 'date' => '2026-09-01', 'quantity' => 1]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'transaction_id' => 'R7', 'line_no' => 2, 'sku' => 'B', 'date' => '2026-09-01', 'quantity' => 1]);
    }

    public function test_duplicate_rule_ignores_multi_line_receipts_and_flags_a_receipt_loaded_twice(): void
    {
        $a = $this->salesImport(self::RECEIPTS);
        $b = $this->salesImport(self::RECEIPTS . "x\n");
        Import::whereKey([$a->id, $b->id])->update(['status' => Import::STATUS_COMPLETED]); // don't defer detection
        $today = now()->toDateString();
        foreach ([1, 2, 3] as $line) {
            SalesTransaction::create(['tenant_id' => $this->tenant->id, 'import_id' => $a->id, 'transaction_id' => 'MULTI', 'line_no' => $line, 'sku' => "M-$line", 'date' => $today, 'quantity' => 1]);
        }
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'import_id' => $a->id, 'transaction_id' => 'TWICE', 'line_no' => 1, 'sku' => 'T-1', 'date' => $today, 'quantity' => 1]);
        SalesTransaction::create(['tenant_id' => $this->tenant->id, 'import_id' => $b->id, 'transaction_id' => 'TWICE', 'line_no' => 7, 'sku' => 'T-1', 'date' => $today, 'quantity' => 1]);

        app(AnomalyDetectionService::class)->runForTenant($this->tenant->id);

        $flags = Anomaly::where('tenant_id', $this->tenant->id)->where('rule_type', 'duplicate_transaction_ids')->get();
        $this->assertSame(['TWICE'], $flags->pluck('context.transaction_id')->all());
    }

    public function test_fuzzy_mapping_never_maps_a_line_total_onto_the_line_number(): void
    {
        $maps = app(ColumnMappingService::class)->map(['Line Total', 'Line No'], [], Import::TYPE_SALES);
        $byHeader = collect($maps)->pluck('target_field', 'source_header');

        $this->assertNotSame('line_no', $byHeader['Line Total']);
        $this->assertSame('line_no', $byHeader['Line No']);
    }
}
