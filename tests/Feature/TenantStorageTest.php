<?php

namespace Tests\Feature;

use App\Services\Storage\TenantStorage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * 3a — private, tenant-isolated file storage: files land under tenant/{id}/…,
 * cross-tenant and traversal access is refused, and a readable local path is
 * always available.
 */
class TenantStorageTest extends TestCase
{
    private TenantStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('autnyx.storage_disk', 'local');
        $this->storage = new TenantStorage();
    }

    /** @return resource */
    private function stream(string $contents)
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, $contents);
        rewind($h);

        return $h;
    }

    public function test_put_stores_under_the_tenant_prefix(): void
    {
        $path = $this->storage->putStream(42, TenantStorage::CATEGORY_IMPORTS, $this->stream('a,b,c'), 'csv');

        $this->assertStringStartsWith('tenant/42/imports/', $path);
        $this->assertStringEndsWith('.csv', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_assert_within_blocks_cross_tenant_and_traversal(): void
    {
        $path = $this->storage->putStream(42, TenantStorage::CATEGORY_IMPORTS, $this->stream('x'), 'csv');

        // Same tenant → fine.
        $this->storage->assertWithin(42, $path);

        // Another tenant → refused.
        $this->expectException(RuntimeException::class);
        $this->storage->assertWithin(99, $path);
    }

    public function test_traversal_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->storage->assertWithin(42, 'tenant/42/../43/imports/secret.csv');
    }

    public function test_exists_and_delete_are_tenant_guarded(): void
    {
        $path = $this->storage->putStream(7, TenantStorage::CATEGORY_IMPORTS, $this->stream('data'), 'csv');

        $this->assertTrue($this->storage->exists(7, $path));

        // A different tenant cannot probe or delete it.
        try {
            $this->storage->delete(8, $path);
            $this->fail('cross-tenant delete should be refused');
        } catch (RuntimeException $e) {
            // expected
        }
        Storage::disk('local')->assertExists($path);

        $this->storage->delete(7, $path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_local_path_returns_a_readable_file(): void
    {
        $path = $this->storage->putStream(1, TenantStorage::CATEGORY_IMPORTS, $this->stream('hello'), 'txt');

        $local = $this->storage->localPath('local', $path);

        $this->assertFileExists($local);
        $this->assertSame('hello', file_get_contents($local));
    }
}
