<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2e — data retention: `data:purge` deletes rows older than each table's window
 * (config/retention.php), keeps recent rows, and never touches NULL-dated rows.
 */
class DataRetentionTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        // Deterministic windows for the test.
        config()->set('retention.tables', [
            'sales_daily' => ['days' => 550, 'column' => 'date'],
            'audit_logs'  => ['days' => 1095, 'column' => 'created_at'],
        ]);
    }

    private function salesDaily(string $sku, string $date): void
    {
        DB::table('sales_daily')->insert([
            'tenant_id'         => $this->tenant->id,
            'sku'               => $sku,
            'date'              => $date,
            'units_sold'        => 1,
            'revenue'           => 1,
            'transaction_count' => 1,
        ]);
    }

    private function audit(string $desc, $createdAt): void
    {
        DB::table('audit_logs')->insert([
            'tenant_id'   => $this->tenant->id,
            'event_type'  => 'test',
            'description' => $desc,
            'created_at'  => $createdAt,
        ]);
    }

    public function test_purges_old_rows_and_keeps_recent(): void
    {
        $this->salesDaily('OLD', now()->subDays(600)->toDateString());   // beyond 550
        $this->salesDaily('NEW', now()->subDays(10)->toDateString());    // within 550
        $this->audit('old-audit', now()->subDays(1200));                 // beyond 1095
        $this->audit('new-audit', now()->subDays(30));                   // within 1095

        $this->artisan('data:purge')->assertExitCode(0);

        $this->assertDatabaseMissing('sales_daily', ['sku' => 'OLD']);
        $this->assertDatabaseHas('sales_daily', ['sku' => 'NEW']);
        $this->assertDatabaseMissing('audit_logs', ['description' => 'old-audit']);
        $this->assertDatabaseHas('audit_logs', ['description' => 'new-audit']);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->salesDaily('OLD', now()->subDays(600)->toDateString());

        $this->artisan('data:purge', ['--dry-run' => true])->assertExitCode(0);

        $this->assertDatabaseHas('sales_daily', ['sku' => 'OLD']);
    }

    public function test_table_option_limits_scope(): void
    {
        $this->salesDaily('OLD', now()->subDays(600)->toDateString());
        $this->audit('old-audit', now()->subDays(1200));

        $this->artisan('data:purge', ['--table' => 'audit_logs'])->assertExitCode(0);

        // Only audit_logs was in scope.
        $this->assertDatabaseMissing('audit_logs', ['description' => 'old-audit']);
        $this->assertDatabaseHas('sales_daily', ['sku' => 'OLD']);
    }

    public function test_unknown_table_option_fails(): void
    {
        $this->artisan('data:purge', ['--table' => 'nope'])->assertExitCode(1);
    }
}
