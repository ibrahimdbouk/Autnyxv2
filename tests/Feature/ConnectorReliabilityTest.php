<?php

namespace Tests\Feature;

use App\Models\ApiConnection;
use App\Models\ApiFeed;
use App\Models\Import;
use App\Models\SalesTransaction;
use App\Models\SftpConnection;
use App\Models\SftpFeed;
use App\Models\SftpIngestedFile;
use App\Models\Tenant;
use App\Services\Integrations\ApiPollService;
use App\Services\Integrations\ConnectorRegistry;
use App\Services\Integrations\GenericRestConnector;
use App\Services\Sftp\SftpPollService;
use App\Services\Sftp\SftpService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * WP3.7 (audit H29) — SFTP files are identified by path + size + mtime, only
 * taken when complete, retried with backoff and archived under unique names;
 * API pulls page to the end, retry 429/5xx, pull incrementally, split line
 * items and say when a cap cut them short; everything is processed by the
 * chunked pipeline.
 */
class ConnectorReliabilityTest extends TestCase
{
    private Tenant $tenant;
    private Filesystem $remote;
    private SftpConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => null]);
        $this->tenant = $this->createTenant();
        Storage::fake('local');
        $this->remote = Storage::fake('remote');

        $this->conn = SftpConnection::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Feed', 'host' => 'sftp.example.com', 'port' => 22,
            'username' => 'u', 'auth_type' => SftpConnection::AUTH_PASSWORD, 'password' => 'p', 'base_path' => '/', 'is_active' => true,
        ]);
        SftpFeed::create([
            'tenant_id' => $this->tenant->id, 'sftp_connection_id' => $this->conn->id, 'data_type' => Import::TYPE_SALES,
            'remote_path' => 'in', 'filename_pattern' => '*.csv', 'archive_path' => 'archive', 'enabled' => true, 'date_format' => 'Y-m-d',
        ]);

        $sftp = \Mockery::mock(SftpService::class);
        $sftp->shouldReceive('disk')->andReturn($this->remote);
        $sftp->shouldReceive('cleanError')->andReturnUsing(fn ($m) => $m);
        $this->app->instance(SftpService::class, $sftp);
    }

    private function putRemote(string $path, string $csv, int $mtime): void
    {
        $this->remote->put($path, $csv);
        touch($this->remote->path($path), $mtime);
        clearstatcache();
    }

    private function poll(): int
    {
        return app(SftpPollService::class)->pollConnection($this->conn->fresh());
    }

    private const CSV = "date,sku,location,quantity\n2026-04-03,A,Downtown,1\n2026-04-03,B,Downtown,2\n";

    public function test_a_new_file_is_taken_only_once_it_stops_changing(): void
    {
        $this->putRemote('in/sales.csv', self::CSV, 1_700_000_000);

        $this->assertSame(0, $this->poll(), 'first sighting: maybe still uploading');
        $this->assertSame(SftpIngestedFile::STATUS_PENDING, SftpIngestedFile::sole()->status);

        $this->assertSame(1, $this->poll(), 'unchanged on the next poll → complete');
        $this->assertSame(2, SalesTransaction::count(), 'processed by the chunked pipeline');
        $this->assertSame(Import::PHASE_WRITE, Import::sole()->process_phase);
    }

    public function test_a_done_marker_releases_a_file_immediately_and_is_never_imported(): void
    {
        $this->putRemote('in/sales.csv', self::CSV, 1_700_000_000);
        $this->putRemote('in/sales.csv.done', '', 1_700_000_000);

        $this->assertSame(1, $this->poll());
        $this->assertSame(1, Import::count());
    }

    public function test_a_file_overwritten_at_the_same_path_is_new_data(): void
    {
        $this->putRemote('in/sales.csv', self::CSV, 1_700_000_000);
        $this->putRemote('in/sales.csv.done', '', 1_700_000_000);
        $this->poll();

        // Archived under a unique name; tomorrow's file arrives at the same path.
        $this->putRemote('in/sales.csv', self::CSV . "2026-04-04,C,Downtown,3\n", 1_700_086_400);
        $this->putRemote('in/sales.csv.done', '', 1_700_086_400);
        $this->assertSame(1, $this->poll());

        $this->assertSame(2, Import::count());
        $this->assertCount(2, $this->remote->files('archive'), 'both archived — the second did not overwrite the first');
    }

    public function test_files_ingested_before_the_change_are_not_re_imported(): void
    {
        $this->putRemote('in/old.csv', self::CSV, 1_700_000_000);
        SftpIngestedFile::create([
            'tenant_id' => $this->tenant->id, 'sftp_connection_id' => $this->conn->id, 'remote_path' => 'in/old.csv',
            'filename' => 'old.csv', 'size_bytes' => strlen(self::CSV), 'status' => SftpIngestedFile::STATUS_IMPORTED,
        ]);

        $this->poll();
        $this->poll();

        $this->assertSame(0, Import::count());
        $this->assertSame(1_700_000_000, (int) SftpIngestedFile::sole()->remote_mtime, 'its identity is completed instead');
    }

    public function test_a_failed_file_is_retried_with_backoff_then_given_up(): void
    {
        $this->putRemote('in/empty.csv', '', 1_700_000_000);
        $this->putRemote('in/empty.csv.done', '', 1_700_000_000);

        $this->poll();
        $f = SftpIngestedFile::sole();
        $this->assertSame(SftpIngestedFile::STATUS_FAILED, $f->status);
        $this->assertSame(1, $f->attempts);

        $this->poll();
        $this->assertSame(1, $f->fresh()->attempts, 'not before its next attempt time');

        for ($i = 0; $i < 10; $i++) {
            $this->travel(7)->hours();
            $this->poll();
        }
        $this->assertSame(SftpIngestedFile::MAX_ATTEMPTS, $f->fresh()->attempts, 'gives up after the maximum');
    }

    // ── API ──────────────────────────────────────────────────────────────────

    public function test_pages_continue_past_a_short_page_and_429_is_retried(): void
    {
        Sleep::fake();
        Http::fake([
            'api.test/*' => Http::sequence()
                ->push(['data' => [['id' => 1], ['id' => 2]]], 200)
                ->push([], 429, ['Retry-After' => '2'])
                ->push(['data' => [['id' => 3]]], 200)   // short page — the server capped it
                ->push(['data' => [['id' => 4]]], 200)
                ->push(['data' => []], 200),
        ]);

        $feed = new ApiFeed(['endpoint' => '/x', 'records_path' => 'data', 'page_strategy' => 'page', 'page_size' => 2]);
        $rows = iterator_to_array((new GenericRestConnector())->fetch(new ApiConnection(['base_url' => 'https://api.test', 'auth_type' => 'none']), $feed), false);

        $this->assertSame([1, 2, 3, 4], array_column($rows, 'id'));
        Sleep::assertSleptTimes(1);
    }

    public function test_line_items_split_into_rows_with_header_fields(): void
    {
        Http::fake(['api.test/*' => Http::response(['orders' => [
            ['order' => 'O1', 'date' => '2026-04-03', 'lines' => [['sku' => 'A', 'qty' => 1], ['sku' => 'B', 'qty' => 2]]],
        ]])]);

        $feed = new ApiFeed(['endpoint' => '/o', 'records_path' => 'orders', 'page_strategy' => 'none', 'split_path' => 'lines']);
        $rows = iterator_to_array((new GenericRestConnector())->fetch(new ApiConnection(['base_url' => 'https://api.test', 'auth_type' => 'none']), $feed), false);

        $this->assertSame([
            ['order' => 'O1', 'date' => '2026-04-03', 'sku' => 'A', 'qty' => 1],
            ['order' => 'O1', 'date' => '2026-04-03', 'sku' => 'B', 'qty' => 2],
        ], $rows);
    }

    private function apiFeed(): ApiFeed
    {
        $conn = ApiConnection::create([
            'tenant_id' => $this->tenant->id, 'name' => 'ERP', 'provider' => 'generic', 'base_url' => 'https://api.test',
            'auth_type' => 'none', 'auth_config' => [], 'is_active' => true, 'status' => ApiConnection::STATUS_NEVER,
        ]);

        return ApiFeed::create([
            'api_connection_id' => $conn->id, 'tenant_id' => $this->tenant->id, 'data_type' => Import::TYPE_SALES,
            'endpoint' => '/sales', 'records_path' => 'value', 'page_strategy' => 'none', 'enabled' => true,
            'params' => ['$filter' => "changed gt '{{since}}'"], 'hwm_field' => 'changed',
        ]);
    }

    public function test_pulls_are_incremental_from_the_high_water_mark(): void
    {
        $feed = $this->apiFeed();
        Http::fake(['api.test/*' => Http::response(['value' => [
            ['date' => '2026-04-03', 'sku' => 'A', 'quantity' => 1, 'changed' => '2026-04-03T10:00:00Z'],
            ['date' => '2026-04-03', 'sku' => 'B', 'quantity' => 1, 'changed' => '2026-04-03T12:00:00Z'],
        ]])]);

        app(ApiPollService::class)->pollConnection($feed->connection);
        $this->assertSame('2026-04-03T12:00:00Z', $feed->fresh()->high_water_mark);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), "changed gt '1970-01-01T00:00:00Z'"));

        app(ApiPollService::class)->pollConnection($feed->connection);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), "changed gt '2026-04-03T12:00:00Z'"));
    }

    public function test_a_capped_pull_warns_and_keeps_its_mark(): void
    {
        $feed = $this->apiFeed();
        $feed->forceFill(['high_water_mark' => '2026-01-01T00:00:00Z', 'page_strategy' => 'page', 'page_size' => 1])->save();
        Http::fake(['api.test/*' => Http::response(['value' => [['date' => '2026-04-03', 'sku' => 'A', 'quantity' => 1, 'changed' => '2026-04-05T00:00:00Z']]])]);

        $this->app->instance(ConnectorRegistry::class, new class extends ConnectorRegistry {
            public function for(ApiConnection $connection): \App\Services\Integrations\Contracts\Connector
            {
                return new class extends GenericRestConnector {
                    protected int $maxPages = 3;
                };
            }
        });

        app(ApiPollService::class)->pollConnection($feed->connection);

        $feed->refresh();
        $this->assertStringContainsString('page limit', (string) $feed->last_warning);
        $this->assertSame('2026-01-01T00:00:00Z', $feed->high_water_mark, 'the rest is fetched next time');
    }
}
