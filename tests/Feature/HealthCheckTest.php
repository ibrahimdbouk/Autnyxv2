<?php

namespace Tests\Feature;

use App\Models\JobRun;
use App\Models\TenantNightlyRun;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WP5.3 (audit M29) — the hourly health check sees a stopped worker, a tenant
 * whose night failed, and only recent queue failures; it alerts once per
 * problem set rather than every hour.
 */
class HealthCheckTest extends TestCase
{
    private function alerts(User $admin): int
    {
        return DB::table('notifications')->where('notifiable_id', $admin->id)->count();
    }

    public function test_a_stopped_worker_a_failed_night_and_fresh_job_failures_alert_once(): void
    {
        $tenant = $this->createTenant(['status' => 'active', 'name' => 'Midan']);
        $admin  = User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]);
        JobRun::create(['command' => 'nightly:dispatch', 'status' => 'success', 'ran_at' => now()]);
        TenantNightlyRun::create(['tenant_id' => $tenant->id, 'local_date' => now()->toDateString(), 'status' => 'failed', 'finished_at' => now()]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(45)->timestamp, 'created_at' => now()->subMinutes(45)->timestamp]);
        DB::table('failed_jobs')->insert(['uuid' => 'old', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}',
            'exception' => 'x', 'failed_at' => now()->subDays(3)]);

        $this->assertSame(0, Artisan::call('system:health-check'));
        $out = Artisan::output();
        $this->assertStringContainsString('waited 45 min', $out);
        $this->assertStringContainsString('Midan: last nightly run failed', $out);
        $this->assertStringNotContainsString('failed in the last 24h', $out, 'a 3-day-old failure is history');
        $this->assertSame(1, $this->alerts($admin));

        $this->artisan('system:health-check')->assertSuccessful();
        $this->assertSame(1, $this->alerts($admin), 'the same problems do not alert again within 6 hours');
    }

    public function test_a_healthy_platform_is_quiet(): void
    {
        $tenant = $this->createTenant(['status' => 'active']);
        JobRun::create(['command' => 'nightly:dispatch', 'status' => 'success', 'ran_at' => now()]);
        TenantNightlyRun::create(['tenant_id' => $tenant->id, 'local_date' => now()->toDateString(), 'status' => 'done', 'finished_at' => now()]);

        $this->artisan('system:health-check')->expectsOutputToContain('System health: OK')->assertSuccessful();
    }
}
