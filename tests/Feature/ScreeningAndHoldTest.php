<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Import;
use App\Models\ImportQuality;
use App\Models\Product;
use App\Models\QuarantinedRow;
use App\Models\SalesTransaction;
use App\Models\Tenant;
use App\Services\DataQuality\DataReadinessService;
use App\Services\Import\ImportProcessorService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WP3.6 (audit H28, D6) — a file is screened before anything is written; a
 * RED batch is held until an admin promotes it; writer failures count in the
 * verdict; a re-upload is its own harmless state; quarantine can be re-screened
 * or promoted.
 */
class ScreeningAndHoldTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        Storage::fake('local');
    }

    private const MAP = ['Date' => 'date', 'SKU' => 'sku', 'Store' => 'location', 'Qty' => 'quantity'];

    private function import(string $csv, string $name = 'f.csv'): Import
    {
        $path = 'imports/pending/' . uniqid() . '.csv';
        Storage::disk('local')->put($path, $csv);
        $import = Import::create([
            'tenant_id' => $this->tenant->id, 'original_filename' => $name, 'disk' => 'local', 'path' => $path,
            'data_type' => Import::TYPE_SALES, 'status' => Import::STATUS_UPLOADED, 'total_rows' => substr_count(trim($csv), "\n"), 'date_format' => 'Y-m-d',
        ]);
        foreach (self::MAP as $h => $f) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $f, 'is_skipped' => false, 'is_confirmed' => true]);
        }

        return $import;
    }

    private function drive(Import $import, bool $start = true): Import
    {
        $svc = app(ImportProcessorService::class);
        if ($start) {
            $svc->startChunkedImport($import);
        }
        $guard = 0;
        do {
            $r = $svc->processChunk($import->fresh(), 3);
        } while (! ($r['done'] ?? false) && ++$guard < 50);

        return $import->fresh();
    }

    private function csv(int $good, int $keyless): string
    {
        $csv = "Date,SKU,Store,Qty\n";
        for ($i = 0; $i < $good; $i++) {
            $csv .= "2026-04-03,G{$i},Downtown,1\n";
        }
        for ($i = 0; $i < $keyless; $i++) {
            $csv .= "2026-04-03,,Downtown,1\n";
        }

        return $csv;
    }

    public function test_a_red_batch_is_held_with_nothing_written_until_an_admin_promotes_it(): void
    {
        $admin = $this->createUser($this->tenant, admin: true);
        $import = $this->drive($this->import($this->csv(4, 6)));

        $this->assertSame(Import::STATUS_HELD, $import->status);
        $this->assertSame(0, SalesTransaction::count(), 'nothing loaded from a RED batch');
        $this->assertSame(6, QuarantinedRow::where('import_id', $import->id)->count());
        $this->assertGreaterThan(0, $admin->notifications()->count(), 'admins are told');

        app(ImportProcessorService::class)->promoteHeld($import, $admin);
        $import = $this->drive($import, start: false);

        $this->assertSame(4, SalesTransaction::count(), 'the clean rows load; quarantine stays');
        $this->assertSame(Import::STATUS_COMPLETED_WITH_ERRORS, $import->status, 'quarantined rows count as errors');
        $q = ImportQuality::where('import_id', $import->id)->sole();
        $this->assertSame($admin->id, (int) $q->overridden_by);
        $this->assertStringStartsWith('Promoted by', $q->decision);
        $this->assertSame(6, QuarantinedRow::where('import_id', $import->id)->count(), 'not quarantined twice');
        $this->assertTrue(AuditLog::where('event_type', 'import_promoted_despite_quality')->exists());
    }

    public function test_the_held_import_page_offers_promotion_to_admins_only(): void
    {
        $import = $this->drive($this->import($this->csv(1, 9)));
        $url = \App\Filament\Resources\ImportResource::getUrl('view', ['record' => $import, 'tenant' => $this->tenant]);

        $this->actingAs($this->createUser($this->tenant, admin: true));
        $this->get($url)->assertOk()->assertSee("mountAction('promote_held')", false);

        $this->actingAs($this->createUser($this->tenant));
        $this->get($url)->assertDontSee("mountAction('promote_held')", false);
    }

    public function test_a_clean_batch_is_screened_then_written(): void
    {
        $import = $this->drive($this->import($this->csv(7, 0)));

        $this->assertSame(Import::STATUS_COMPLETED, $import->status);
        $this->assertSame(Import::PHASE_WRITE, $import->process_phase);
        $this->assertSame(7, SalesTransaction::count());
        $this->assertSame(ImportQuality::STATE_GREEN, ImportQuality::where('import_id', $import->id)->value('state'));
    }

    public function test_the_single_pass_path_holds_a_red_batch_too(): void
    {
        $import = $this->import($this->csv(2, 8));
        app(ImportProcessorService::class)->process($import);

        $this->assertSame(Import::STATUS_HELD, $import->fresh()->status);
        $this->assertSame(0, SalesTransaction::count());
    }

    public function test_rows_that_fail_to_write_turn_the_verdict_red(): void
    {
        // Non-strict: an unreadable quantity is only a warning at the gate, but the writer rejects it.
        $csv = "Date,SKU,Store,Qty\n";
        for ($i = 0; $i < 10; $i++) {
            $csv .= "2026-04-03,S{$i},Downtown,lots\n";
        }
        $import = $this->drive($this->import($csv));

        $this->assertSame(10, (int) $import->failed_rows);
        $q = ImportQuality::where('import_id', $import->id)->sole();
        $this->assertSame(ImportQuality::STATE_RED, $q->state, 'no longer "100% clean"');
        $this->assertStringContainsString('failed to load', $q->decision);
    }

    public function test_a_re_upload_is_a_duplicate_state_that_readiness_ignores(): void
    {
        $csv = $this->csv(5, 0);
        $this->drive($this->import($csv));
        $again = $this->drive($this->import($csv));

        $this->assertSame(ImportQuality::STATE_DUPLICATE, ImportQuality::where('import_id', $again->id)->value('state'));
        $this->assertSame(ImportQuality::STATE_GREEN, app(DataReadinessService::class)->datasetStates($this->tenant->id)[Import::TYPE_SALES]);
        $this->assertSame(5, SalesTransaction::count());
    }

    public function test_quarantined_rows_can_be_re_screened_after_a_fix_or_promoted_by_an_admin(): void
    {
        config(['data_quality.referential_gate' => true]);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'KNOWN', 'name' => 'Known']);
        $csv = "Date,SKU,Store,Qty\n";
        for ($i = 0; $i < 20; $i++) {
            $csv .= "2026-04-03,KNOWN,Downtown,1\n";
        }
        $csv .= "2026-04-03,NEWSKU,Downtown,1\n2026-04-03,OTHER,Downtown,1\n";
        $import = $this->drive($this->import($csv));
        $this->assertSame(2, QuarantinedRow::where('import_id', $import->id)->where('reason_code', 'orphan_reference')->count());

        // The product master gains NEWSKU → re-screen loads it.
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'NEWSKU', 'name' => 'New']);
        $svc = app(ImportProcessorService::class);
        $r = $svc->reprocessQuarantined(QuarantinedRow::where('import_id', $import->id)->get());
        $this->assertSame(['promoted' => 1, 'still' => 1], $r);
        $this->assertSame(1, SalesTransaction::where('sku', 'NEWSKU')->count());

        // OTHER is still unknown — an admin forces it through (audited).
        $admin = $this->createUser($this->tenant, admin: true);
        $r = $svc->reprocessQuarantined(QuarantinedRow::where('import_id', $import->id)->where('status', 'open')->get(), true, $admin);
        $this->assertSame(1, $r['promoted']);
        $this->assertSame(1, SalesTransaction::where('sku', 'OTHER')->count());
        $this->assertSame(QuarantinedRow::STATUS_PROMOTED, QuarantinedRow::where('import_id', $import->id)->where('status', '!=', QuarantinedRow::STATUS_RESOLVED)->value('status'));
        $this->assertTrue(AuditLog::where('event_type', 'quarantine_promoted')->exists());
        $this->assertSame(22, (int) $import->fresh()->imported_rows);
        $this->assertSame(0, (int) $import->fresh()->quarantined_rows);
    }
}
