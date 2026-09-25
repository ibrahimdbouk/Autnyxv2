<?php

namespace Tests\Feature;

use App\Models\ContractViolation;
use App\Models\DataContract;
use App\Models\DqFinding;
use App\Models\EntityAlias;
use App\Models\Import;
use App\Models\ImportQuality;
use App\Models\Product;
use App\Models\QuarantinedRow;
use App\Models\SalesTransaction;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataQuality\AliasSuggester;
use App\Services\DataQuality\DataQualityChecks;
use App\Services\DataQuality\FeedMonitor;
use App\Services\DataQuality\PiiGuard;
use App\Services\DataQuality\QuarantineOps;
use App\Services\DataQuality\Reasons;
use App\Services\Import\ImportProcessorService;
use App\Services\Integrations\PipelineIngestor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * W9 — data trust: rehearsal, feed registry and service levels, semantic
 * data checks, quarantine operations, personal-data guard.
 */
class DataTrustTest extends TestCase
{
    private Tenant $tenant;

    private const SALES_MAP = ['Date' => 'date', 'SKU' => 'sku', 'Store' => 'location', 'Qty' => 'quantity', 'Total' => 'total_amount'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        Storage::fake('local');
        Queue::fake();
        \Illuminate\Support\Facades\Notification::fake();
    }

    private function import(string $csv, array $map = self::SALES_MAP, array $attrs = [], string $type = Import::TYPE_SALES): Import
    {
        $path = 'imports/pending/f-' . uniqid() . '.csv';
        Storage::disk('local')->put($path, $csv);
        $lines = count(array_filter(explode("\n", trim($csv)))) - 1;
        $import = Import::create(array_merge([
            'tenant_id' => $this->tenant->id, 'original_filename' => 'file.csv', 'disk' => 'local', 'path' => $path,
            'data_type' => $type, 'status' => Import::STATUS_UPLOADED, 'total_rows' => $lines,
            'source' => Import::SOURCE_UPLOAD, 'feed_key' => Import::feedKeyFor(Import::SOURCE_UPLOAD, $type),
        ], $attrs));
        foreach ($map as $h => $f) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $f, 'is_skipped' => false, 'is_confirmed' => true]);
        }

        return $import;
    }

    private function runImport(Import $import): Import
    {
        $svc = app(ImportProcessorService::class);
        $svc->startChunkedImport($import);
        $guard = 0;
        do {
            $r = $svc->processChunk($import->fresh(), 100);
        } while (! ($r['done'] ?? false) && ++$guard < 50);

        return $import->fresh();
    }

    private function salesCsv(int $rows, string $header = 'Date,SKU,Store,Qty,Total', ?string $date = null): string
    {
        static $call = 0;
        $date ??= now()->subDays(++$call)->toDateString();   // distinct files: identical ones are skipped as duplicates
        $out = [$header];
        for ($i = 1; $i <= $rows; $i++) {
            $out[] = "{$date},SKU-{$i},S1,1,10";
        }

        return implode("\n", $out) . "\n";
    }

    private function sftpImport(string $csv, array $map = self::SALES_MAP): Import
    {
        return $this->import($csv, $map, ['source' => Import::SOURCE_SFTP, 'source_ref' => '7',
            'feed_key' => Import::feedKeyFor(Import::SOURCE_SFTP, Import::TYPE_SALES, 7)]);
    }

    // ── WP9.1 rehearsal ──────────────────────────────────────────────────────

    public function test_a_rehearsal_reports_both_gate_settings_and_writes_nothing(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'KNOWN', 'name' => 'K', 'unit_cost' => 1, 'selling_price' => 2]);
        $csv = "Date,SKU,Store,Qty,Total\n"
            . "2026-09-01,KNOWN,S1,1,10\n2026-09-01,KNOWN,S2,2,20\n"
            . "2026-09-01,,S1,1,10\n"           // no key: quarantined either way
            . "2026-09-01,GHOST,S1,1,10\n"      // orphan: a warning now, quarantined with the referential gate
            . "2026-09-01,KNOWN,S3,,10\n";      // no quantity: quarantined only in strict mode
        $import = $this->import($csv);

        $before = [SalesTransaction::count(), QuarantinedRow::count(), ImportQuality::count(), DataContract::count()];
        $this->artisan('imports:rehearse', ['import' => [$import->id]])->assertSuccessful()
            ->expectsOutputToContain('all gates:')
            ->expectsOutputToContain(Reasons::label(Reasons::MISSING_KEY));
        $r = app(ImportProcessorService::class)->rehearse($import);

        $this->assertSame($before, [SalesTransaction::count(), QuarantinedRow::count(), ImportQuality::count(), DataContract::count()], 'nothing written');
        $this->assertSame(5, $r['rows']);
        $this->assertSame(1, $r['modes']['configured']['quarantined']);
        $this->assertSame(1, $r['modes']['configured']['warnings'][Reasons::WARN_ORPHAN_SKU] ?? 0);
        $this->assertSame(3, $r['modes']['all_gates']['quarantined']);
        $this->assertArrayHasKey(Reasons::ORPHAN_REFERENCE, $r['modes']['all_gates']['rejections']);
        $this->assertSame(4, $r['modes']['configured']['samples'][Reasons::MISSING_KEY][0]['row']);
        $this->assertSame(Import::STATUS_UPLOADED, $import->fresh()->status);
    }

    public function test_a_rehearsal_of_a_file_that_is_no_longer_stored_fails_loudly(): void
    {
        $import = $this->import($this->salesCsv(3));
        Storage::disk('local')->delete($import->path);

        $this->artisan('imports:rehearse', ['import' => [$import->id]])->assertFailed()
            ->expectsOutputToContain('stored file is gone');
    }

    /** W9: uploads went to the container's ephemeral disk on production and were lost on each deploy. */
    public function test_tenant_files_follow_the_default_private_disk_unless_set_explicitly(): void
    {
        $cfg = fn (array $env) => (function () use ($env) {
            foreach ($env as $k => $v) {
                $v === null ? putenv($k) : putenv("{$k}={$v}");
            }
            try {
                return (require base_path('config/autnyx.php'))['storage_disk'];
            } finally {
                foreach (array_keys($env) as $k) {
                    putenv($k);
                }
            }
        })();

        $this->assertSame('private', $cfg(['FILESYSTEM_DISK' => 'private', 'AUTNYX_STORAGE_DISK' => null]));
        $this->assertSame('s3', $cfg(['FILESYSTEM_DISK' => 'private', 'AUTNYX_STORAGE_DISK' => 's3']));
        $this->assertSame('local', $cfg(['FILESYSTEM_DISK' => null, 'AUTNYX_STORAGE_DISK' => null]));
    }

    public function test_the_health_check_flags_uploads_kept_on_an_ephemeral_disk_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['autnyx.storage_disk' => 'local', 'backup.enabled' => false]);
        $this->artisan('system:health-check')->expectsOutputToContain('ephemeral');

        config(['autnyx.storage_disk' => 's3', 'filesystems.disks.s3.driver' => 's3']);
        $this->artisan('system:health-check')->doesntExpectOutputToContain('ephemeral');
    }

    // ── WP9.2 feeds ──────────────────────────────────────────────────────────

    public function test_every_ingestion_path_tags_its_feed(): void
    {
        $ing = app(PipelineIngestor::class);
        $pushed = $ing->ingestRows($this->tenant->id, Import::TYPE_SALES, [['date' => '2026-09-01', 'sku' => 'A', 'quantity' => 1]], 'apiingest', queue: true);
        $pulled = $ing->ingestRows($this->tenant->id, Import::TYPE_SALES, [['date' => '2026-09-01', 'sku' => 'A', 'quantity' => 1]], 'api', sourceRef: 12);

        $this->assertSame(['ingest', 'ingest:sales_transactions'], [$pushed->source, $pushed->feed_key]);
        $this->assertSame(['api', '12', 'api:12:sales_transactions'], [$pulled->source, $pulled->source_ref, $pulled->feed_key]);
    }

    public function test_a_feed_learns_its_volume_and_shape_then_flags_breaks(): void
    {
        $admin = $this->createUser($this->tenant, admin: true);
        foreach ([0, 1, 2] as $day) {
            $i = $this->runImport($this->sftpImport($this->salesCsv(20)));
            Import::whereKey($i->id)->update(['created_at' => now()->subDays(3 - $day)]);
        }
        $c = DataContract::where('tenant_id', $this->tenant->id)->where('feed_key', 'sftp:7:sales_transactions')->firstOrFail();
        $this->assertSame([3, 20, 8, 50], [$c->batches_seen, $c->rows_median, $c->rows_low, $c->rows_high]);
        $this->assertSame(Import::SOURCE_SFTP, $c->source);
        $this->assertSame(DataContract::STATUS_OK, $c->status);

        // Twice the usual volume and a new column: loaded, flagged.
        $big = $this->runImport($this->sftpImport($this->salesCsv(60, 'Date,SKU,Store,Qty,Total,Promo'), self::SALES_MAP + ['Promo' => 'promotion_ref']));
        $this->assertSame(Import::STATUS_COMPLETED, $big->status);
        $kinds = ContractViolation::where('import_id', $big->id)->pluck('kind')->all();
        $this->assertEqualsCanonicalizing([ContractViolation::KIND_VOLUME_HIGH, ContractViolation::KIND_SCHEMA_DRIFT], $kinds);
        $q = ImportQuality::where('import_id', $big->id)->first();
        $this->assertArrayHasKey(Reasons::FEED_VOLUME_HIGH, $q->reason_counts);
        $this->assertSame(DataContract::STATUS_WARNING, $c->fresh()->status);
        \Illuminate\Support\Facades\Notification::assertSentTo($admin, \Filament\Notifications\DatabaseNotification::class);
    }

    public function test_a_partial_automated_sales_file_is_held_before_anything_is_written(): void
    {
        foreach (range(1, 3) as $n) {
            $this->runImport($this->sftpImport($this->salesCsv(40)));
        }
        $rowsBefore = SalesTransaction::count();

        $partial = $this->runImport($this->sftpImport($this->salesCsv(5)));

        $this->assertSame(Import::STATUS_HELD, $partial->status);
        $this->assertStringContainsString('Partial delivery', $partial->error_message);
        $this->assertSame($rowsBefore, SalesTransaction::count(), 'nothing from the partial file was loaded');
        $this->assertSame(ImportQuality::STATE_RED, ImportQuality::where('import_id', $partial->id)->value('state'));
        $this->assertTrue(ContractViolation::where('import_id', $partial->id)->where('kind', ContractViolation::KIND_VOLUME_LOW)->exists());
        $c = DataContract::where('feed_key', 'sftp:7:sales_transactions')->first();
        $this->assertSame(40, $c->rows_median, 'a held batch does not drag the usual volume down');

        // An uploaded file of the same size is a person's choice: never held.
        foreach (range(1, 3) as $n) {
            $this->runImport($this->import($this->salesCsv(40)));
        }
        $this->assertSame(Import::STATUS_COMPLETED, $this->runImport($this->import($this->salesCsv(5)))->status);

        // Promoting the held batch loads it and counts it once.
        app(ImportProcessorService::class)->promoteHeld($partial, $this->createUser($this->tenant, admin: true));
        $svc = app(ImportProcessorService::class);
        do {
            $r = $svc->processChunk($partial->fresh(), 100);
        } while (! ($r['done'] ?? false));
        $this->assertSame(Import::STATUS_COMPLETED, $partial->fresh()->status);
        $this->assertSame(4, $c->fresh()->batches_seen);
    }

    public function test_an_automated_feed_that_stops_is_reported_late_once_and_cleared_by_the_next_batch(): void
    {
        $c = DataContract::create(['tenant_id' => $this->tenant->id, 'feed_key' => 'sftp:7:sales_transactions', 'data_type' => Import::TYPE_SALES,
            'source' => Import::SOURCE_SFTP, 'learned' => true, 'active' => true, 'batches_seen' => 5, 'expected_every_hours' => 24,
            'rows_median' => 20, 'rows_low' => 8, 'rows_high' => 50, 'last_batch_at' => now()->subHours(30)]);
        $upload = DataContract::create(['tenant_id' => $this->tenant->id, 'feed_key' => 'upload:inventory_levels', 'data_type' => Import::TYPE_INVENTORY,
            'source' => Import::SOURCE_UPLOAD, 'learned' => true, 'active' => true, 'batches_seen' => 9, 'expected_every_hours' => 24, 'last_batch_at' => now()->subDays(20)]);

        $this->artisan('feeds:check')->assertSuccessful()->expectsOutputToContain('0 feed(s) newly late');   // 30h < 24h + 12h

        $c->update(['last_batch_at' => now()->subHours(40)]);
        $this->artisan('feeds:check')->assertSuccessful()->expectsOutputToContain('1 feed(s) newly late');
        $this->artisan('feeds:check')->assertSuccessful()->expectsOutputToContain('0 feed(s) newly late');
        $this->assertSame(DataContract::STATUS_LATE, $c->fresh()->status);
        $this->assertSame(0, ContractViolation::where('data_contract_id', $upload->id)->count(), 'uploads are a person\'s rhythm: never "late"');

        $this->runImport($this->sftpImport($this->salesCsv(20)));
        $this->assertNotNull(ContractViolation::where('data_contract_id', $c->id)->where('kind', ContractViolation::KIND_LATE)->value('resolved_at'));
        $this->assertSame(DataContract::STATUS_OK, $c->fresh()->status);

        // An explicit service level applies to any feed.
        $upload->update(['freshness_sla_hours' => 48]);
        $this->artisan('feeds:check')->assertSuccessful()->expectsOutputToContain('1 feed(s) newly late');
    }

    public function test_required_columns_on_a_contract_are_enforced(): void
    {
        $i = $this->sftpImport($this->salesCsv(5));
        DataContract::create(['tenant_id' => $this->tenant->id, 'feed_key' => $i->feed_key, 'data_type' => Import::TYPE_SALES, 'source' => 'sftp',
            'active' => true, 'required_columns' => ['Date', 'SKU', 'Store', 'Qty', 'Total', 'Cost']]);
        $this->runImport($i);
        $this->assertSame('Missing required columns: Cost.', ContractViolation::where('import_id', $i->id)->value('detail'));
    }

    // ── WP9.3 data checks ────────────────────────────────────────────────────

    private function store(string $code): int
    {
        return DB::table('stores')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => "Store {$code}", 'code' => $code, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function daily(int $store, string $sku, string $date, float $units, float $revenue): void
    {
        DB::table('sales_daily')->insert(['tenant_id' => $this->tenant->id, 'store_id' => $store, 'sku' => $sku, 'date' => $date,
            'units_sold' => $units, 'revenue' => $revenue, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_data_checks_find_silent_stores_future_rows_and_negative_stock_then_resolve(): void
    {
        $admin = $this->createUser($this->tenant, admin: true);
        $a = $this->store('A');
        $b = $this->store('B');
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'P1', 'name' => 'P1', 'unit_cost' => 5, 'selling_price' => 10]);
        $end = Carbon::parse('2026-09-20');
        for ($d = 30; $d >= 0; $d--) {
            $date = $end->copy()->subDays($d)->toDateString();
            $this->daily($a, 'P1', $date, 30, 300);
            if ($d >= 4) {
                $this->daily($b, 'P1', $date, 30, 300);   // B stops four days before the end
            }
        }
        $this->daily($a, 'P1', now()->addDays(40)->toDateString(), 1, 10);   // a day/month mix-up
        DB::table('inventory_current')->insert(['tenant_id' => $this->tenant->id, 'store_id' => $a, 'sku' => 'P1', 'as_of_date' => '2026-09-20',
            'on_hand_qty' => -4, 'created_at' => now(), 'updated_at' => now()]);

        $r = app(DataQualityChecks::class)->run($this->tenant->id);

        $open = DqFinding::where('tenant_id', $this->tenant->id)->open()->get()->keyBy('check');
        $this->assertSame('store:' . $b, $open['store_went_silent']->subject_key);
        $this->assertStringContainsString('Store B', $open['store_went_silent']->message);
        $this->assertSame(DqFinding::SEVERITY_CRITICAL, $open['future_dated']->severity);
        $this->assertSame(1, $open['negative_stock']->metrics['positions']);
        $this->assertSame(3, $r['opened']);
        \Illuminate\Support\Facades\Notification::assertSentToTimes($admin, \Filament\Notifications\DatabaseNotification::class, 1);

        // Seen again: counted, not re-alerted.
        app(DataQualityChecks::class)->run($this->tenant->id);
        $this->assertSame(2, DqFinding::where('check', 'future_dated')->value('occurrences'));
        \Illuminate\Support\Facades\Notification::assertSentToTimes($admin, \Filament\Notifications\DatabaseNotification::class, 1);

        // Fixed: resolved.
        DB::table('sales_daily')->where('date', '>', now()->addDays(2)->toDateString())->delete();
        DB::table('inventory_current')->update(['on_hand_qty' => 3]);
        $r = app(DataQualityChecks::class)->run($this->tenant->id);
        $this->assertSame(2, $r['resolved']);
        $this->assertSame(['store_went_silent'], DqFinding::where('tenant_id', $this->tenant->id)->open()->pluck('check')->all());
    }

    public function test_data_checks_find_a_half_loaded_day_price_outliers_and_missing_costs(): void
    {
        $a = $this->store('A');
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'P1', 'name' => 'P1', 'unit_cost' => 5, 'selling_price' => 10]);
        Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'P2', 'name' => 'P2', 'unit_cost' => 0, 'selling_price' => 4]);
        $end = Carbon::parse('2026-09-20');
        foreach ([28, 21, 14, 7] as $back) {
            $this->daily($a, 'P1', $end->copy()->subDays($back)->toDateString(), 100, 1000);
        }
        $this->daily($a, 'P1', $end->toDateString(), 10, 100);        // 10% of a normal Sunday
        $this->daily($a, 'P2', $end->toDateString(), 2, 800);         // 400 each vs a list price of 4

        app(DataQualityChecks::class)->run($this->tenant->id, alert: false);
        $open = DqFinding::where('tenant_id', $this->tenant->id)->open()->get()->keyBy('check');

        $this->assertSame(DqFinding::SEVERITY_CRITICAL, $open['sales_day_collapse']->severity);
        $this->assertSame('2026-09-20', $open['sales_day_collapse']->subject);
        $this->assertSame(1, $open['price_outliers']->metrics['skus']);
        $this->assertSame(1, $open['cost_missing']->metrics['products']);
    }

    public function test_the_nightly_health_step_runs_the_data_checks(): void
    {
        $ref = new \ReflectionClassConstant(\App\Services\Pipeline\NightlyChain::class, 'COMMANDS');
        $this->assertContains('dq:check', $ref->getValue()['health']);
        $this->artisan('dq:check', ['--tenant' => $this->tenant->id])->assertSuccessful();
    }

    // ── WP9.4 quarantine operations ──────────────────────────────────────────

    private function quarantined(string $sku, string $reason, int $daysOld = 0, string $type = Import::TYPE_SALES): QuarantinedRow
    {
        $import = Import::create(['tenant_id' => $this->tenant->id, 'original_filename' => 'q.csv', 'disk' => 'local', 'path' => 'x.csv',
            'data_type' => $type, 'status' => Import::STATUS_COMPLETED, 'total_rows' => 1]);
        foreach (self::SALES_MAP as $h => $field) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $field, 'is_skipped' => false, 'is_confirmed' => true]);
        }
        $row = QuarantinedRow::create(['tenant_id' => $this->tenant->id, 'import_id' => $import->id, 'data_type' => $type, 'row_number' => 2,
            'raw_data' => ['Date' => '2026-09-01', 'SKU' => $sku, 'Store' => 'S1', 'Qty' => '1', 'Total' => '10'], 'cleansed_data' => ['date' => '2026-09-01', 'sku' => $sku, 'location' => 'S1', 'quantity' => 1, 'total_amount' => 10],
            'reason_code' => $reason, 'severity' => 'high', 'message' => Reasons::label($reason), 'status' => QuarantinedRow::STATUS_OPEN]);
        if ($daysOld) {
            QuarantinedRow::whereKey($row->id)->update(['created_at' => now()->subDays($daysOld)]);
        }

        return $row;
    }

    public function test_unknown_skus_that_match_one_known_sku_are_suggested_and_accepting_loads_their_rows(): void
    {
        config(['data_quality.referential_gate' => true]);
        foreach (['123A', 'AB-7', 'AB7'] as $sku) {
            Product::create(['tenant_id' => $this->tenant->id, 'sku' => $sku, 'name' => $sku, 'unit_cost' => 1, 'selling_price' => 2]);
        }
        $this->quarantined('00123-A', Reasons::ORPHAN_REFERENCE);
        $this->quarantined('00123-A', Reasons::ORPHAN_REFERENCE);
        $this->quarantined('ab 7', Reasons::ORPHAN_REFERENCE);     // AB-7 or AB7? ambiguous: never suggested
        $this->quarantined('ZZZ', Reasons::ORPHAN_REFERENCE);

        $s = app(AliasSuggester::class)->suggest($this->tenant->id);
        $this->assertSame([['alias' => '00123-A', 'canonical' => '123A', 'rows' => 2, 'source' => 'quarantine']], $s);

        $user = $this->createUser($this->tenant, admin: true);
        $r = app(QuarantineOps::class)->acceptAliases($this->tenant->id, [['alias' => '00123-A', 'canonical' => '123A']], $user);

        $this->assertSame('123A', EntityAlias::where('tenant_id', $this->tenant->id)->where('alias', '00123-a')->value('canonical'));
        $this->assertSame(2, $r['promoted']);
        $this->assertSame(2, SalesTransaction::where('tenant_id', $this->tenant->id)->where('sku', '123A')->count());
        $this->assertSame([], app(AliasSuggester::class)->suggest($this->tenant->id));
    }

    public function test_every_open_row_with_one_reason_can_be_discarded_at_once_and_old_ones_are_flagged(): void
    {
        $this->quarantined('A', Reasons::MISSING_KEY, 10);
        $this->quarantined('B', Reasons::MISSING_KEY, 10, Import::TYPE_INVENTORY);
        $this->quarantined('C', Reasons::INVALID_DATE, 1);

        app(DataQualityChecks::class)->run($this->tenant->id, alert: false);
        $this->assertEqualsCanonicalizing(['type:sales_transactions', 'type:inventory_levels'],
            DqFinding::where('check', 'quarantine_aging')->open()->pluck('subject_key')->all());

        $user = $this->createUser($this->tenant, admin: true);
        $r = app(QuarantineOps::class)->applyToReason($this->tenant->id, Reasons::MISSING_KEY, 'skip', Import::TYPE_SALES, $user);
        $this->assertSame(['done' => 1, 'promoted' => 0, 'still' => 0, 'remaining' => 0], $r);
        $this->assertEqualsCanonicalizing([Reasons::MISSING_KEY => 1, Reasons::INVALID_DATE => 1], app(QuarantineOps::class)->openByReason($this->tenant->id));
        $this->assertTrue(\App\Models\AuditLog::where('event_type', 'quarantine_bulk_skip')->exists());
    }

    // ── WP9.6 lineage, and the screens ──────────────────────────────────────

    public function test_an_investigation_shows_the_batches_its_rows_came_from(): void
    {
        $good = $this->runImport($this->import("Date,SKU,Store,Qty,Total\n2026-09-10,LIN-1,S1,1,10\n2026-09-11,LIN-1,S1,2,20\n"));
        $other = $this->runImport($this->import("Date,SKU,Store,Qty,Total\n2026-09-12,OTHER,S1,1,10\n"));
        ImportQuality::where('import_id', $good->id)->update(['overridden_at' => now()]);
        $store = SalesTransaction::where('import_id', $good->id)->value('store_id');

        $inv = \App\Models\Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'primary_sku' => 'LIN-1', 'primary_store_id' => $store,
            'status' => \App\Models\Investigation::STATUS_OPEN, 'opened_at' => '2026-09-13']);
        \App\Models\Anomaly::factory()->create(['tenant_id' => $this->tenant->id, 'sku' => 'LIN-1', 'store_id' => $store,
            'investigation_id' => $inv->id, 'detected_at' => '2026-09-13']);

        $lin = app(\App\Services\DataQuality\DataLineage::class)->forInvestigation($inv);
        $this->assertSame([$good->id], array_column($lin['batches'], 'id'), 'only the batches behind this SKU');
        $this->assertSame(['rows' => 2, 'from' => '2026-09-10', 'to' => '2026-09-11'], $lin['batches'][0]['datasets']['Sales']);
        $this->assertContains('loaded despite a RED verdict', $lin['batches'][0]['flags']);

        $this->actingAsTenantAdmin($this->tenant);
        $this->get(\App\Filament\Resources\InvestigationResource::getUrl('investigate', ['record' => $inv->id, 'tenant' => $this->tenant]))
            ->assertOk()->assertSee('Data lineage')->assertSee('#' . $good->id . ' · file.csv', false)->assertDontSee('#' . $other->id . ' · file.csv', false);
    }

    public function test_data_health_lists_open_findings_and_feeds_for_admins(): void
    {
        $this->runImport($this->sftpImport($this->salesCsv(12)));
        DqFinding::create(['tenant_id' => $this->tenant->id, 'check' => 'negative_stock', 'dataset' => 'inventory', 'severity' => 'warning',
            'subject_key' => 'tenant', 'subject' => 'Current stock', 'message' => '3 position(s) have negative stock on hand.',
            'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->actingAsTenantAdmin($this->tenant);
        $this->get(\App\Filament\Pages\DataHealthCenter::getUrl(['tenant' => $this->tenant]))->assertOk()
            ->assertSee('id="checks"', false)->assertSee('Negative stock on hand')->assertSee('3 position(s) have negative stock on hand.')
            ->assertSee('id="feeds"', false)->assertSee('Sales Transactions — SFTP')->assertSee('learning — 1 batch(es) so far')
            ->assertSee("mountAction('feedSettings'", false);

        $this->get(\App\Filament\Resources\QuarantinedRowResource::getUrl('index', ['tenant' => $this->tenant]))->assertOk()
            ->assertSee("mountAction('aliasSuggestions'", false)->assertSee("mountAction('byReason'", false);
    }

    // ── WP9.5 personal data ─────────────────────────────────────────────────

    public function test_personal_data_columns_are_recognised_from_their_values(): void
    {
        $rows = [];
        $cards = ['4111 1111 1111 1111', '5500-0000-0000-0004', '340000000000009', '6011000000000004'];
        $eans = ['6291041500213', '5000112637922', '8710398527882', '4006381333931'];
        foreach (range(0, 3) as $i) {
            $rows[] = ['Email' => "user{$i}@example.com", 'Mobile' => '0501234' . $i . '67', 'Intl' => "+971 50 123 45{$i}{$i}",
                'Card' => $cards[$i], 'Barcode' => $eans[$i], 'Date' => "2026-09-0{$i}", 'Amount' => '1 234.5' . $i, 'SKU' => "SKU-{$i}"];
        }
        $found = PiiGuard::detect($rows, ['Email' => null]);

        $this->assertSame(['Email' => 'email', 'Mobile' => 'phone', 'Intl' => 'phone', 'Card' => 'card'], $found);
        $this->assertSame(['Card' => 'card'], PiiGuard::detect($rows, ['Email' => 'email', 'Mobile' => 'phone', 'Intl' => 'contact_phone', 'Card' => 'contact_phone']),
            'a contact column of a supplier or user is not personal data to strip — a card number always is');
        $this->assertSame('u***@e***.com', PiiGuard::mask('user1@example.com', 'email'));
        $this->assertSame('***1111', PiiGuard::mask('4111 1111 1111 1111', 'card'));
        $this->assertSame(PiiGuard::pseudonym(1, '+971 50 123 4567'), PiiGuard::pseudonym(1, '+971501234567'));
        $this->assertNotSame(PiiGuard::pseudonym(1, 'a@b.co'), PiiGuard::pseudonym(2, 'a@b.co'), 'pseudonyms are per tenant');
    }

    public function test_personal_data_is_masked_wherever_raw_rows_are_kept_and_customer_refs_are_pseudonymised(): void
    {
        $csv = "Date,SKU,Store,Qty,Total,Customer\n"
            . "2026-09-01,A,S1,1,10,anna@example.com\n2026-09-01,B,S1,1,10,omar@example.org\n";
        foreach (range(1, 18) as $n) {
            $csv .= "2026-09-01,C{$n},S1,1,10,\n";   // no customer: most receipts are anonymous
        }
        $csv .= "2026-09-01,,S1,1,10,lina@example.net\n";
        $import = $this->runImport($this->import($csv, self::SALES_MAP + ['Customer' => 'customer_ref']));

        $q = ImportQuality::where('import_id', $import->id)->first();
        $this->assertSame([['column' => 'Customer', 'kind' => 'email', 'field' => 'customer_ref']], $q->pii_columns);
        $this->assertSame(1, $q->reason_counts[Reasons::PII_DETECTED]);

        $refs = SalesTransaction::where('import_id', $import->id)->whereNotNull('customer_ref')->pluck('customer_ref')->all();
        $this->assertCount(2, $refs);
        foreach ($refs as $ref) {
            $this->assertMatchesRegularExpression('/^cust_[0-9a-f]{16}$/', $ref);
        }
        $this->assertSame(PiiGuard::pseudonym($this->tenant->id, 'anna@example.com'), SalesTransaction::where('sku', 'A')->value('customer_ref'));

        $qr = QuarantinedRow::where('import_id', $import->id)->first();
        $this->assertSame('l***@e***.net', $qr->raw_data['Customer']);
        $this->assertStringNotContainsString('lina@', json_encode($qr->cleansed_data));
        $this->assertStringNotContainsString('@example', json_encode($import->sample_rows));
    }
}
