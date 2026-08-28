<?php

namespace Tests\Feature;

use App\Http\Controllers\ImpersonationController;
use App\Models\AuditLog;
use App\Models\Import;
use App\Models\JobRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ops\GrowthMetricsService;
use App\Services\Ops\PlatformHealthService;
use App\Services\Ops\TenantUsageService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ops console enrichment — platform health, growth metrics, per-tenant profile,
 * suspension lockout, and auth observability.
 */
class OpsConsoleTest extends TestCase
{
    // ── Platform health ──────────────────────────────────────────────────────

    public function test_pipeline_returns_latest_run_per_command(): void
    {
        JobRun::create(['command' => 'data:purge', 'status' => JobRun::STATUS_FAILED,  'duration_ms' => 100, 'ran_at' => now()->subDays(2)]);
        JobRun::create(['command' => 'data:purge', 'status' => JobRun::STATUS_SUCCESS, 'duration_ms' => 250, 'ran_at' => now()->subHour()]);
        JobRun::create(['command' => 'anomaly:detect', 'status' => JobRun::STATUS_SUCCESS, 'duration_ms' => 900, 'ran_at' => now()->subHours(3)]);

        $pipeline = collect(app(PlatformHealthService::class)->pipeline())->keyBy('command');

        $this->assertCount(2, $pipeline, 'one row per command');
        $this->assertSame(JobRun::STATUS_SUCCESS, $pipeline['data:purge']['status'], 'latest run wins');
        $this->assertSame(250, $pipeline['data:purge']['duration_ms']);
    }

    public function test_summary_flags_recent_pipeline_failure(): void
    {
        $svc = app(PlatformHealthService::class);
        $this->assertTrue($svc->summary()['pipeline_ok'], 'no runs → healthy');

        JobRun::create(['command' => 'anomaly:detect', 'status' => JobRun::STATUS_FAILED, 'ran_at' => now()->subHours(2)]);
        $this->assertFalse($svc->summary()['pipeline_ok'], 'recent failure → not ok');
    }

    public function test_import_health_counts_stuck_and_failed(): void
    {
        $t = $this->createTenant();

        $stuck = Import::create(['tenant_id' => $t->id, 'original_filename' => 'a.csv', 'path' => 'a', 'data_type' => 'products', 'status' => Import::STATUS_IMPORTING, 'total_rows' => 10]);
        DB::table('imports')->where('id', $stuck->id)->update(['updated_at' => now()->subMinutes(30)]);

        Import::create(['tenant_id' => $t->id, 'original_filename' => 'b.csv', 'path' => 'b', 'data_type' => 'products', 'status' => Import::STATUS_FAILED, 'total_rows' => 5]);
        Import::create(['tenant_id' => $t->id, 'original_filename' => 'c.csv', 'path' => 'c', 'data_type' => 'products', 'status' => Import::STATUS_COMPLETED, 'total_rows' => 5]);

        $imports = app(PlatformHealthService::class)->imports();

        $this->assertSame(1, $imports['stuck']);
        $this->assertSame(1, $imports['failed']);
        $this->assertSame(1, $imports['completed']);
    }

    // ── Growth metrics ───────────────────────────────────────────────────────

    public function test_series_fills_a_continuous_window(): void
    {
        $today = now()->format('Y-m-d');
        $series = app(GrowthMetricsService::class)->series([$today => 4], 30);

        $this->assertCount(30, $series, 'padded to the full window');
        $this->assertSame(4, end($series)['value'], 'today carries its value');
        $this->assertSame(0, $series[0]['value'], 'empty days fill with 0');
    }

    public function test_new_tenants_daily_counts_by_day(): void
    {
        $this->createTenant(['created_at' => now()]);
        $this->createTenant(['created_at' => now()]);
        $this->createTenant(['created_at' => now()->subDays(60)]); // outside window

        $map = app(GrowthMetricsService::class)->newTenantsDaily(30);

        $this->assertSame(2, (int) ($map[now()->format('Y-m-d')] ?? 0));
    }

    // ── Per-tenant profile ───────────────────────────────────────────────────

    public function test_for_tenant_returns_a_structured_profile(): void
    {
        $t = $this->createTenant(['name' => 'Deep Dive']);
        User::factory()->tenantAdmin()->create(['tenant_id' => $t->id]);
        User::factory()->count(2)->create(['tenant_id' => $t->id]);

        $profile = app(TenantUsageService::class)->forTenant($t);

        $this->assertSame($t->id, $profile['tenant']->id);
        $this->assertSame(3, $profile['volumes']['users']);
        $this->assertSame(1, $profile['volumes']['admins']);
        $this->assertArrayHasKey('observed_total', $profile['recovery']);
        $this->assertArrayHasKey('enabled', $profile['sso']);
        $this->assertCount(3, $profile['users']);
    }

    // ── Suspension lockout ───────────────────────────────────────────────────

    public function test_suspended_tenant_locks_out_its_own_users(): void
    {
        $t    = $this->createTenant(['status' => Tenant::STATUS_SUSPENDED]);
        $user = $this->createUser($t);

        $this->assertFalse($user->canAccessTenant($t), 'non-super user of a suspended tenant is locked out');
    }

    public function test_super_admin_and_impersonation_bypass_suspension(): void
    {
        $t     = $this->createTenant(['status' => Tenant::STATUS_SUSPENDED]);
        $super = User::factory()->superAdmin()->create(['tenant_id' => $this->createTenant()->id]);

        $this->assertTrue($super->canAccessTenant($t), 'super admin is never locked out');

        $user = $this->createUser($t);
        session()->put(ImpersonationController::SESSION_KEY, $super->id);
        $this->assertTrue($user->canAccessTenant($t), 'active impersonation bypasses the lockout');
    }

    public function test_active_tenant_users_keep_access(): void
    {
        $t    = $this->createTenant(['status' => Tenant::STATUS_ACTIVE]);
        $user = $this->createUser($t);

        $this->assertTrue($user->canAccessTenant($t));
    }

    // ── Auth observability ───────────────────────────────────────────────────

    public function test_login_stamps_last_login_at(): void
    {
        $t    = $this->createTenant();
        $user = $this->createUser($t);
        $this->assertNull($user->last_login_at);

        event(new Login('web', $user, false));

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_failed_login_is_audited_for_known_user(): void
    {
        $t    = $this->createTenant();
        $user = $this->createUser($t);

        event(new Failed('web', null, ['email' => $user->email, 'password' => 'wrong']));

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id'  => $t->id,
            'user_id'    => $user->id,
            'event_type' => AuditLog::EVENT_LOGIN_FAILED,
        ]);
    }

    public function test_failed_login_for_unknown_email_is_ignored(): void
    {
        event(new Failed('web', null, ['email' => 'nobody@nowhere.test', 'password' => 'x']));

        $this->assertDatabaseMissing('audit_logs', ['event_type' => AuditLog::EVENT_LOGIN_FAILED]);
    }
}
