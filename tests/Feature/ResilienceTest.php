<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ops\DatabaseBackup;
use App\Support\Database\OwnerConnection;
use App\Support\Security\SecretsInventory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WP8.1 (audit L13) — backups that restore, a runtime role that can only
 * touch rows, migrations as the owner, and secrets that are inventoried and
 * never defaulted.
 */
class ResilienceTest extends TestCase
{
    private function needsPgTools(): void
    {
        $v = @shell_exec('pg_dump --version 2>/dev/null');
        $server = (int) DB::selectOne('SHOW server_version_num')->server_version_num;
        if (! $v || ! preg_match('/(\d+)\.\d+/', $v, $m) || (int) $m[1] < intdiv($server, 10000)) {
            $this->markTestSkipped('pg_dump matching the server is not installed here.');
        }
    }

    public function test_a_backup_is_stored_with_a_manifest_and_restores_into_a_scratch_database(): void
    {
        $this->needsPgTools();
        Storage::fake('backups');
        config(['backup.disk' => 'backups']);
        $svc = app(DatabaseBackup::class);

        $r = $svc->run();

        Storage::disk('backups')->assertExists($r['path']);
        $manifest = json_decode(Storage::disk('backups')->get(substr($r['path'], 0, -5) . '.json'), true);
        $this->assertSame($r['sha256'], $manifest['sha256']);
        $this->assertGreaterThan(50, $manifest['tables'] ? count($manifest['tables']) : 0);
        $this->assertSame($r['path'], $svc->latest()['path']);

        if (! DB::selectOne('SELECT rolcreatedb OR rolsuper AS ok FROM pg_roles WHERE rolname = current_user')->ok) {
            $this->markTestSkipped('The test role cannot create databases.');
        }
        $drill = $svc->restoreDrill();
        $this->assertTrue($drill['checksum_ok']);
        $this->assertSame([], $drill['comparison']['missing_tables']);
        $this->assertSame($drill['comparison']['migrations_live'], $drill['comparison']['migrations_restored']);
        $this->assertNull(DB::selectOne('SELECT 1 AS x FROM pg_database WHERE datname = ?', [$drill['scratch_database']]), 'scratch database dropped');
    }

    public function test_retention_keeps_two_weeks_of_dailies_then_one_a_week(): void
    {
        Storage::fake('backups');
        config(['backup.disk' => 'backups', 'backup.keep_daily' => 14, 'backup.keep_weekly' => 8]);
        $now = Carbon::parse('2026-09-25 03:15', 'UTC');
        for ($d = 0; $d < 100; $d++) {
            $at = $now->copy()->subDays($d);
            Storage::disk('backups')->put('backups/db/autnyx-' . $at->format(DatabaseBackup::STAMP) . 'Z.dump', 'x');
            Storage::disk('backups')->put('backups/db/autnyx-' . $at->format(DatabaseBackup::STAMP) . 'Z.json', '{}');
        }

        $deleted = app(DatabaseBackup::class)->prune($now);
        $kept = app(DatabaseBackup::class)->list();

        $this->assertCount(15 + 7, $kept, '15 dailies (today and 14 days back), then one per older week within 8 weeks');
        $this->assertCount(100 - count($kept), $deleted);
        $this->assertTrue($kept[count($kept) - 1]['at']->gte($now->copy()->subWeeks(8)));
        Storage::disk('backups')->assertMissing(substr($deleted[0], 0, -5) . '.json');
    }

    public function test_backups_run_nightly_and_a_missing_one_is_reported(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'db:backup'));
        $this->assertCount(1, $events);
        $this->assertSame('15 3 * * *', $events->first()->expression);

        config(['backup.enabled' => true]);
        \App\Models\JobRun::create(['command' => 'db:backup', 'status' => 'success', 'ran_at' => now()->subHours(40)]);
        $stale = collect(app(\App\Services\Ops\PlatformHealthService::class)->staleCommands())->pluck('command');
        $this->assertContains('db:backup', $stale->all());

        // A manual run counts as the latest backup.
        $this->needsPgTools();
        Storage::fake('backups');
        config(['backup.disk' => 'backups']);
        $this->artisan('db:backup')->assertSuccessful();
        $this->assertNotContains('db:backup', collect(app(\App\Services\Ops\PlatformHealthService::class)->staleCommands())->pluck('command')->all());
    }

    public function test_the_runtime_role_can_touch_rows_but_never_the_schema(): void
    {
        putenv('DB_RUNTIME_PASSWORD=' . str_repeat('k7', 16));
        try {
            $this->artisan('db:runtime-role --role=autnyx_rt_test')->assertSuccessful();
        } finally {
            putenv('DB_RUNTIME_PASSWORD');
        }

        $t = $this->createTenant();
        DB::statement('SET ROLE autnyx_rt_test');
        try {
            $this->assertSame($t->name, DB::table('tenants')->where('id', $t->id)->value('name'));
            DB::table('tenants')->where('id', $t->id)->update(['name' => 'Renamed']);
            $this->assertThrows(fn () => DB::transaction(fn () => DB::statement('CREATE TABLE rt_probe (id int)')), \Illuminate\Database\QueryException::class);
            $this->assertThrows(fn () => DB::transaction(fn () => DB::statement('ALTER TABLE tenants ADD COLUMN rt_probe int')), \Illuminate\Database\QueryException::class);
            $this->assertThrows(fn () => DB::transaction(fn () => DB::statement('TRUNCATE sales_daily')), \Illuminate\Database\QueryException::class);
            DB::statement('CREATE TEMP TABLE rt_tmp (id int)');   // the baseline computation needs temp tables
        } finally {
            DB::statement('RESET ROLE');
        }
        $this->assertSame('Renamed', $t->fresh()->name);

        $this->artisan('db:runtime-role --role=autnyx_rt_test --check')->assertSuccessful()->expectsOutputToContain('least privilege — OK');
    }

    public function test_the_role_password_is_required_and_never_printed(): void
    {
        putenv('DB_RUNTIME_PASSWORD=short');
        try {
            $this->artisan('db:runtime-role --role=autnyx_rt_test')->assertFailed()->doesntExpectOutputToContain('short');
        } finally {
            putenv('DB_RUNTIME_PASSWORD');
        }
    }

    public function test_schema_commands_switch_to_the_owner_credentials(): void
    {
        $this->assertTrue(OwnerConnection::needsOwner('migrate'));
        $this->assertTrue(OwnerConnection::needsOwner('db:backup'));
        $this->assertFalse(OwnerConnection::needsOwner('queue:work'));
        $this->assertFalse(OwnerConnection::needsOwner('nightly:dispatch'));

        $default = config('database.default');
        config(['database.connections.owner_probe' => config("database.connections.{$default}"), 'database.default' => 'owner_probe']);
        try {
            $this->assertFalse(OwnerConnection::use(), 'no owner configured: nothing changes');
            config(['database.owner.username' => 'autnyx_owner', 'database.owner.password' => 'x']);
            $this->assertTrue(OwnerConnection::use());
            $this->assertSame('autnyx_owner', config('database.connections.owner_probe.username'));
        } finally {
            config(['database.default' => $default, 'database.owner.username' => '', 'database.owner.password' => '']);
        }
    }

    public function test_the_seeder_never_creates_an_admin_with_a_default_password(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertNull(User::where('email', 'admin@autnyx.io')->first(), 'no ADMIN_PASSWORD, no account');

        putenv('ADMIN_PASSWORD=' . str_repeat('Zq9', 6));
        try {
            $this->seed(DatabaseSeeder::class);
        } finally {
            putenv('ADMIN_PASSWORD');
        }
        $admin = User::where('email', 'admin@autnyx.io')->firstOrFail();
        $this->assertFalse(Hash::check(SecretsInventory::OLD_ADMIN_DEFAULT, $admin->password));
    }

    public function test_the_secrets_check_flags_the_old_default_admin_password(): void
    {
        $t = $this->createTenant();
        User::factory()->create(['tenant_id' => $t->id, 'email' => 'admin@autnyx.io', 'password' => Hash::make(SecretsInventory::OLD_ADMIN_DEFAULT)]);

        $this->artisan('security:secrets')->assertFailed()
            ->expectsOutputToContain('old seeded default password')
            ->doesntExpectOutputToContain(SecretsInventory::OLD_ADMIN_DEFAULT);
    }
}
