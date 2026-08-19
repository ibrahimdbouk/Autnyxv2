<?php

namespace Tests\Feature;

use App\Jobs\BulkInvestigationActionJob;
use App\Models\Investigation;
use App\Services\Noise\SnoozeService;
use Tests\TestCase;

class BulkActionsTest extends TestCase
{
    public function test_bulk_priority_change_is_tenant_scoped_with_partial_failures(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        $user    = $this->createUser($tenantA);

        $invA = Investigation::factory()->create(['tenant_id' => $tenantA->id, 'priority' => 'low']);
        $invB = Investigation::factory()->create(['tenant_id' => $tenantB->id, 'priority' => 'low']);

        $result = BulkInvestigationActionJob::apply(
            $tenantA->id,
            $user->id,
            'change_priority',
            [$invA->id, $invB->id, 999999],
            ['priority' => 'high'],
            app(SnoozeService::class),
        );

        $this->assertSame(1, $result['succeeded']);
        $this->assertSame(2, $result['failed']); // cross-tenant + missing id

        $this->assertSame('high', $invA->fresh()->priority);
        $this->assertSame('low', $invB->fresh()->priority); // untouched — different tenant
    }

    public function test_bulk_assign_to_me(): void
    {
        $tenant = $this->createTenant();
        $user   = $this->createUser($tenant);
        $inv    = Investigation::factory()->create(['tenant_id' => $tenant->id]);

        $result = BulkInvestigationActionJob::apply(
            $tenant->id,
            $user->id,
            'assign_to_me',
            [$inv->id],
            [],
            app(SnoozeService::class),
        );

        $this->assertSame(1, $result['succeeded']);
        $this->assertSame($user->id, $inv->fresh()->assigned_user_id);
    }
}
