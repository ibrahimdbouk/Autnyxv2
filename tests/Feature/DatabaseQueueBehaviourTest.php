<?php

namespace Tests\Feature;

use App\Jobs\RunTenantDetectionJob;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * WP5.1 (audit M27) — behaviour the sync queue and array cache hide: jobs
 * dispatched after commit never leave a rolled-back transaction, and a unique
 * job is queued once. Runs in the CI job that uses the database queue and
 * cache (QUEUE_CONNECTION=database, CACHE_STORE=database); skipped otherwise.
 * No wrapping test transaction here — the queue and lock rows must commit.
 */
#[Group('database-queue')]
class DatabaseQueueBehaviourTest extends TestCase
{
    protected $connectionsToTransact = [];

    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('queue.default') !== 'database' || config('cache.default') !== 'database') {
            $this->markTestSkipped('Runs in the database-queue CI job.');
        }
        DB::table('jobs')->delete();
        DB::table('cache_locks')->delete();
        $this->tenant = $this->createTenant();
    }

    protected function tearDown(): void
    {
        if ($this->tenant) {
            DB::table('jobs')->delete();
            DB::table('cache_locks')->delete();
            $this->tenant->delete();
        }
        parent::tearDown();
    }

    public function test_an_after_commit_job_never_leaves_a_rolled_back_transaction(): void
    {
        try {
            DB::transaction(function () {
                RunTenantDetectionJob::dispatch($this->tenant->id)->afterCommit();
                throw new \RuntimeException('import failed');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::table('jobs')->count());

        DB::transaction(fn () => RunTenantDetectionJob::dispatch($this->tenant->id)->afterCommit());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_one_detection_run_per_tenant_is_queued(): void
    {
        RunTenantDetectionJob::dispatch($this->tenant->id, 'incremental');
        RunTenantDetectionJob::dispatch($this->tenant->id, 'full');

        $this->assertSame(1, DB::table('jobs')->count());
    }
}
