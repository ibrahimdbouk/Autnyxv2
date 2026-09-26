<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Import;
use App\Models\SftpConnection;
use App\Models\SftpFeed;
use App\Services\Sftp\SftpPollService;
use App\Services\Sftp\SftpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * W13 — files the platform pulls or is pushed (SFTP, API ingest) land on the
 * secure private disk under the tenant's prefix, like an upload — not on the
 * app server's own disk — and import from there.
 */
class PulledFilesLandingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['autnyx.storage_disk' => 'secure', 'filesystems.disks.secure' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/secure')]]);
        Storage::fake('secure');
        Storage::fake('local');
    }

    public function test_an_api_push_lands_on_the_secure_disk_and_imports(): void
    {
        $tenant = $this->createTenant();
        [, $token] = ApiKey::generate($tenant->id, 'push', [ApiKey::SCOPE_WRITE_INGEST]);

        $id = $this->postJson('/api/v1/ingest', ['data_type' => Import::TYPE_SALES, 'rows' => [
            ['sku' => 'A', 'date' => '2026-09-20', 'quantity' => 2, 'unit_price' => 5],
            ['sku' => 'B', 'date' => '2026-09-20', 'quantity' => 1, 'unit_price' => 7],
        ]], ['X-Api-Key' => $token])->assertStatus(202)->json('import_id');

        $import = Import::findOrFail($id);
        $this->assertSame('secure', $import->disk);
        $this->assertStringStartsWith('tenant/' . $tenant->id . '/imports/', $import->path);
        Storage::disk('secure')->assertExists($import->path);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'nothing left on the app disk');
        $this->assertSame(2, (int) DB::table('sales_transactions')->where('tenant_id', $tenant->id)->count());
    }

    public function test_an_sftp_file_lands_on_the_secure_disk(): void
    {
        $tenant = $this->createTenant();
        $conn = SftpConnection::create(['tenant_id' => $tenant->id, 'name' => 'ERP', 'host' => 'sftp.example.com', 'port' => 22, 'username' => 'u',
            'auth_type' => 'password', 'password' => 'p', 'base_path' => '/in', 'is_active' => true]);
        $feed = SftpFeed::create(['sftp_connection_id' => $conn->id, 'tenant_id' => $tenant->id, 'data_type' => Import::TYPE_SALES,
            'remote_path' => '.', 'filename_pattern' => '*.csv', 'enabled' => true]);

        Storage::fake('remote');
        Storage::disk('remote')->put('sales_0920.csv', "sku,date,quantity,unit_price\nA,2026-09-20,2,5\n");
        Storage::disk('remote')->put('sales_0920.csv.done', '');
        $this->mock(SftpService::class, fn ($m) => $m->shouldReceive('disk')->andReturn(Storage::disk('remote')));

        app(SftpPollService::class)->pollConnection($conn->fresh());

        $import = Import::where('tenant_id', $tenant->id)->sole();
        $this->assertSame('secure', $import->disk);
        $this->assertStringStartsWith('tenant/' . $tenant->id . '/imports/', $import->path);
        $this->assertSame('sales_0920.csv', $import->original_filename);
        Storage::disk('secure')->assertExists($import->path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
