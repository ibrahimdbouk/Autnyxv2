<?php

namespace Tests\Feature;

use App\Filament\Resources\SftpConnectionResource;
use App\Models\SftpConnection;
use App\Models\SftpFeed;
use App\Models\SftpIngestedFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M14 — SFTP connection storage: credential encryption, feed wiring, the
 * idempotency ledger, and admin gating. (No live network I/O here.)
 */
class SftpConnectionTest extends TestCase
{
    private function makeConnection(array $overrides = []): SftpConnection
    {
        $tenant = $this->createTenant();

        return SftpConnection::create(array_merge([
            'tenant_id' => $tenant->id,
            'name'      => 'Acme SFTP',
            'host'      => 'sftp.example.com',
            'port'      => 22,
            'username'  => 'acme',
            'auth_type' => SftpConnection::AUTH_PASSWORD,
            'password'  => 'super-secret',
            'base_path' => '/inbound',
            'is_active' => true,
            'status'    => SftpConnection::STATUS_NEVER,
        ], $overrides));
    }

    public function test_password_is_encrypted_at_rest_but_readable_via_model(): void
    {
        $conn = $this->makeConnection();

        // Raw column value must not be the plaintext.
        $raw = DB::table('sftp_connections')->where('id', $conn->id)->value('password');
        $this->assertNotSame('super-secret', $raw);
        $this->assertNotEmpty($raw);

        // Model accessor decrypts transparently.
        $this->assertSame('super-secret', $conn->fresh()->password);
    }

    public function test_ingested_file_ledger_blocks_duplicate_remote_path(): void
    {
        $conn = $this->makeConnection();

        SftpIngestedFile::create([
            'tenant_id'          => $conn->tenant_id,
            'sftp_connection_id' => $conn->id,
            'remote_path'        => 'inbound/sales_2026-08.csv',
            'filename'           => 'sales_2026-08.csv',
            'status'             => SftpIngestedFile::STATUS_IMPORTED,
            'processed_at'       => now(),
        ]);

        // The unique(sftp_connection_id, remote_path) index must reject a re-insert.
        $this->expectException(QueryException::class);

        SftpIngestedFile::create([
            'tenant_id'          => $conn->tenant_id,
            'sftp_connection_id' => $conn->id,
            'remote_path'        => 'inbound/sales_2026-08.csv',
            'filename'           => 'sales_2026-08.csv',
            'status'             => SftpIngestedFile::STATUS_IMPORTED,
            'processed_at'       => now(),
        ]);
    }

    public function test_same_path_allowed_across_different_connections(): void
    {
        $a = $this->makeConnection();
        $b = $this->makeConnection();

        foreach ([$a, $b] as $conn) {
            SftpIngestedFile::create([
                'tenant_id'          => $conn->tenant_id,
                'sftp_connection_id' => $conn->id,
                'remote_path'        => 'inbound/shared.csv',
                'filename'           => 'shared.csv',
                'status'             => SftpIngestedFile::STATUS_IMPORTED,
                'processed_at'       => now(),
            ]);
        }

        $this->assertSame(2, SftpIngestedFile::where('remote_path', 'inbound/shared.csv')->count());
    }

    public function test_feed_relationship_and_data_type_label(): void
    {
        $conn = $this->makeConnection();

        $feed = SftpFeed::create([
            'sftp_connection_id' => $conn->id,
            'tenant_id'          => $conn->tenant_id,
            'data_type'          => \App\Models\Import::TYPE_SALES,
            'remote_path'        => '.',
            'filename_pattern'   => 'sales_*.csv',
            'enabled'            => true,
        ]);

        $this->assertTrue($conn->feeds()->where('id', $feed->id)->exists());
        $this->assertSame('Sales Transactions', $feed->getDataTypeLabel());
        $this->assertSame($conn->id, $feed->connection->id);
    }

    public function test_resource_is_admin_gated(): void
    {
        $tenant = $this->createTenant();

        $this->actingAsAnalyst($tenant);
        $this->assertFalse(SftpConnectionResource::canAccess(), 'analysts must not access SFTP config');

        $this->actingAsTenantAdmin($tenant);
        $this->assertTrue(SftpConnectionResource::canAccess(), 'tenant admins may access SFTP config');
    }

    // diskConfig() reads encrypted casts, so it needs a booted app (the `encrypter`
    // binding) — hence these live here rather than in the pure-unit feed-matching test.

    public function test_disk_config_uses_password_when_password_auth(): void
    {
        $conn = new SftpConnection([
            'host'      => 'sftp.example.com',
            'port'      => 2222,
            'username'  => 'acme',
            'auth_type' => SftpConnection::AUTH_PASSWORD,
            'password'  => 'secret',
            'base_path' => '/inbound',
        ]);

        $config = $conn->diskConfig();

        $this->assertSame('sftp', $config['driver']);
        $this->assertSame('sftp.example.com', $config['host']);
        $this->assertSame(2222, $config['port']);
        $this->assertSame('acme', $config['username']);
        $this->assertSame('/inbound', $config['root']);
        $this->assertSame('secret', $config['password']);
        $this->assertArrayNotHasKey('privateKey', $config);
    }

    public function test_disk_config_uses_private_key_when_key_auth(): void
    {
        $conn = new SftpConnection([
            'host'                   => 'sftp.example.com',
            'username'               => 'acme',
            'auth_type'              => SftpConnection::AUTH_KEY,
            'private_key'            => '-----BEGIN KEY-----',
            'private_key_passphrase' => 'phrase',
        ]);

        $config = $conn->diskConfig();

        $this->assertSame('-----BEGIN KEY-----', $config['privateKey']);
        $this->assertSame('phrase', $config['passphrase']);
        $this->assertArrayNotHasKey('password', $config);
        $this->assertSame(22, $config['port'], 'port must default to 22');
    }
}
