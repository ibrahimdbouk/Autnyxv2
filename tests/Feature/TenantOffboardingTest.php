<?php

namespace Tests\Feature;

use App\Jobs\Ops\EraseTenantJob;
use App\Models\Anomaly;
use App\Models\Import;
use App\Models\PlatformAuditLog;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ops\TenantOffboardingService;
use App\Services\Storage\TenantStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WP6.7 (audit H7) — erasure removes everything the tenant owns (including the
 * tables with no foreign key and its files), in resumable chunks, after the
 * tenant is closed; the audit record survives; the export never holds a table
 * in memory and never carries secrets.
 */
class TenantOffboardingTest extends TestCase
{
    private function populated(): Tenant
    {
        $t = $this->createTenant(['status' => 'active', 'slug' => 'gone-' . uniqid()]);
        $user = $this->createUser($t, admin: true);
        $store = Store::create(['tenant_id' => $t->id, 'name' => 'Mall']);
        foreach (range(1, 25) as $i) {
            Anomaly::create(['tenant_id' => $t->id, 'rule_type' => 'sales_drop', 'severity' => 'low', 'sku' => "S{$i}",
                'store_id' => $store->id, 'description' => 'x', 'context' => [], 'detected_at' => now()]);
        }
        // Tables with no tenant foreign key (the audit's orphans).
        DB::table('sku_baselines')->insert(['tenant_id' => $t->id, 'sku' => 'S1', 'store_id' => null, 'rule_type' => 'sales_drop',
            'metric' => 'daily_sales_qty', 'baseline_mean' => 1, 'baseline_stddev' => 1, 'sample_count' => 10, 'sensitivity_multiplier' => 2, 'fp_count' => 0,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('job_runs')->insert(['tenant_id' => $t->id, 'command' => 'nightly:detection', 'status' => 'success', 'ran_at' => now()]);
        DB::table('notifications')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class,
            'notifiable_id' => $user->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        // Files: one under the tenant prefix, one legacy import path outside it.
        Storage::fake('local');
        Storage::disk('local')->put(app(TenantStorage::class)->tenantPrefix($t->id) . '/exports/a.csv', 'x');
        Storage::disk('local')->put('imports/pending/legacy.csv', 'x');
        Import::create(['tenant_id' => $t->id, 'original_filename' => 'legacy.csv', 'disk' => 'local', 'path' => 'imports/pending/legacy.csv',
            'data_type' => Import::TYPE_SALES, 'status' => Import::STATUS_COMPLETED]);

        return $t;
    }

    public function test_a_request_closes_the_tenant_at_once_and_queues_the_erase(): void
    {
        Queue::fake();
        $t = $this->populated();
        $user = User::where('tenant_id', $t->id)->first();

        app(TenantOffboardingService::class)->requestErase($t, User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]));

        $this->assertSame(Tenant::STATUS_ERASING, $t->fresh()->status);
        $this->assertFalse($user->canAccessTenant($t->fresh()));
        Queue::assertPushed(EraseTenantJob::class, fn ($j) => $j->tenantId === $t->id);
        $this->assertSame(1, PlatformAuditLog::where('event', 'tenant.erase_requested')->where('subject_id', $t->id)->count());
    }

    public function test_erase_removes_every_tenant_row_including_orphan_tables_and_files(): void
    {
        $t = $this->populated();
        $svc = app(TenantOffboardingService::class);
        $keep = $this->createTenant(['status' => 'active']);
        DB::table('job_runs')->insert(['tenant_id' => $keep->id, 'command' => 'x', 'status' => 'success', 'ran_at' => now()]);

        $svc->eraseNow($t->id);

        $this->assertNull(Tenant::find($t->id));
        foreach ($svc->tenantScopedTables() as $table) {
            $this->assertSame(0, DB::table($table)->where('tenant_id', $t->id)->count(), $table);
        }
        $this->assertSame(0, DB::table('notifications')->where('notifiable_type', User::class)->whereNotIn('notifiable_id', User::select('id'))->count());
        Storage::disk('local')->assertMissing(app(TenantStorage::class)->tenantPrefix($t->id) . '/exports/a.csv');
        Storage::disk('local')->assertMissing('imports/pending/legacy.csv');
        $this->assertSame(1, DB::table('job_runs')->where('tenant_id', $keep->id)->count(), 'another tenant is untouched');
        $this->assertSame(1, PlatformAuditLog::where('event', 'tenant.erased')->where('subject_id', $t->id)->count(), 'the record outlives the tenant');
    }

    public function test_erase_resumes_across_slices(): void
    {
        $t = $this->populated();
        $svc = app(TenantOffboardingService::class);

        $this->assertFalse($svc->eraseChunked($t->id, budgetSeconds: 0, chunk: 5), 'out of time: not done yet');
        $this->assertNotNull(Tenant::find($t->id));
        $this->assertTrue($svc->eraseChunked($t->id, budgetSeconds: 600, chunk: 5));
        $this->assertNull(Tenant::find($t->id));
    }

    public function test_the_protected_tenant_is_never_erased(): void
    {
        $t = $this->createTenant(['slug' => 'autnyx']);

        $this->expectException(\RuntimeException::class);
        app(TenantOffboardingService::class)->requestErase($t);
    }

    public function test_the_export_carries_every_row_and_no_secret(): void
    {
        $t = $this->populated();

        $zipPath = app(TenantOffboardingService::class)->export($t);
        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $users = $zip->getFromName('users.csv');
        $zip->close();
        @unlink($zipPath);

        $this->assertSame(25, $manifest['tables']['anomalies']);
        $this->assertSame(1, $manifest['tables']['sku_baselines']);
        $this->assertStringNotContainsString('password', strtok($users, "\n"));
        $this->assertSame(1, PlatformAuditLog::where('event', 'tenant.exported')->count());
    }
}
