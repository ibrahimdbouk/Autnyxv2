<?php

namespace Tests\Feature;

use App\Models\ImportQuality;
use App\Services\DataQuality\ReadinessAlerter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Data-Quality Firewall — a RED batch must never be silent. The alert reaches the
 * people who can act (the affected tenant's admins + platform super admins) and
 * nobody else, and it is best-effort so it can never break ingestion.
 */
class ReadinessAlerterTest extends TestCase
{
    public function test_red_batch_alerts_tenant_admins_and_super_admins_only(): void
    {
        Mail::fake();

        $tenant = $this->createTenant();
        $other = $this->createTenant();

        $superAdmin = $this->createUser($tenant, superAdmin: true);
        $tenantAdmin = $this->createUser($tenant, admin: true);
        $regular = $this->createUser($tenant);
        $otherTenantAdmin = $this->createUser($other, admin: true);

        $q = ImportQuality::create([
            'tenant_id'        => $tenant->id,
            'data_type'        => 'inventory_levels',
            'rows_promoted'    => 0,
            'rows_quarantined' => 1200,
            'state'            => ImportQuality::STATE_RED,
            'decision'         => 'Blocked: clean-promote rate below threshold.',
            'blocked'          => true,
        ]);

        app(ReadinessAlerter::class)->redBatch($q);

        $count = fn ($id) => DB::table('notifications')->where('notifiable_id', $id)->count();

        $this->assertGreaterThan(0, $count($superAdmin->id), 'super admins are alerted');
        $this->assertGreaterThan(0, $count($tenantAdmin->id), 'the affected tenant\'s admins are alerted');
        $this->assertSame(0, $count($regular->id), 'regular users are not alerted');
        $this->assertSame(0, $count($otherTenantAdmin->id), 'another tenant\'s admins are not alerted');
    }

    public function test_alert_never_throws_even_with_a_minimal_row(): void
    {
        Mail::fake();

        $tenant = $this->createTenant();
        $q = ImportQuality::create([
            'tenant_id' => $tenant->id,
            'data_type' => 'sales_transactions',
            'state'     => ImportQuality::STATE_RED,
            'blocked'   => true,
        ]);

        // No recipients, minimal data — must complete silently, never throw.
        app(ReadinessAlerter::class)->redBatch($q);

        $this->assertTrue(true);
    }
}
