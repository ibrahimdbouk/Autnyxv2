<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\WatchNotification;
use App\Services\Watch\WatchEvaluationService;
use App\Services\Watch\WatchService;
use Tests\TestCase;

class WatchInvestigationTest extends TestCase
{
    public function test_user_can_watch_and_unwatch(): void
    {
        $tenant = $this->createTenant();
        $user   = $this->createUser($tenant);
        $inv    = Investigation::factory()->create(['tenant_id' => $tenant->id]);

        $service = app(WatchService::class);

        $service->watchForUser($inv, $user->id);
        $this->assertTrue($service->isWatchedByUser($inv, $user->id));

        $service->unwatchForUser($inv, $user->id);
        $this->assertFalse($service->isWatchedByUser($inv, $user->id));
    }

    public function test_status_change_notifies_once_and_dedups(): void
    {
        $tenant = $this->createTenant();
        $user   = $this->createUser($tenant);
        $inv    = Investigation::factory()->create(['tenant_id' => $tenant->id, 'status' => 'open']);

        $watch = app(WatchService::class)->watchForUser($inv, $user->id);

        // A meaningful change occurs
        $inv->update(['status' => 'resolved', 'resolved_at' => now()]);

        $sent = app(WatchEvaluationService::class)->evaluateWatch($watch->fresh());
        $this->assertGreaterThan(0, $sent);
        $this->assertTrue(
            WatchNotification::where('watch_id', $watch->id)->where('event_type', 'status_change')->exists()
        );

        // Re-evaluating must not resend
        $before = WatchNotification::count();
        app(WatchEvaluationService::class)->evaluateWatch($watch->fresh());
        $this->assertSame($before, WatchNotification::count());
    }

    public function test_until_resolved_watch_auto_ends_on_resolution(): void
    {
        $tenant = $this->createTenant();
        $user   = $this->createUser($tenant);
        $inv    = Investigation::factory()->create(['tenant_id' => $tenant->id, 'status' => 'open']);

        $watch = app(WatchService::class)->watchForUser($inv, $user->id);
        $inv->update(['status' => 'resolved', 'resolved_at' => now()]);

        app(WatchEvaluationService::class)->evaluateWatch($watch->fresh());
        $this->assertFalse((bool) $watch->fresh()->active);
    }
}
