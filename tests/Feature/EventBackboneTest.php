<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\PlatformEvent;
use App\Services\Platform\EventStore;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * P1.2 — the event backbone: append + replay, idempotent capture, live capture
 * of actions/outcomes as they are created, and an idempotent backfill.
 */
class EventBackboneTest extends TestCase
{
    public function test_append_and_replay_over_a_valid_time_window(): void
    {
        $tenant = $this->createTenant();
        $store  = app(EventStore::class);

        $store->append(['tenant_id' => $tenant->id, 'event_type' => PlatformEvent::TYPE_SALE, 'sku' => 'SKU-1', 'occurred_at' => '2026-03-01 10:00:00', 'quantity' => 5]);
        $store->append(['tenant_id' => $tenant->id, 'event_type' => PlatformEvent::TYPE_SALE, 'sku' => 'SKU-1', 'occurred_at' => '2026-03-20 10:00:00', 'quantity' => 3]);
        $store->append(['tenant_id' => $tenant->id, 'event_type' => PlatformEvent::TYPE_SALE, 'sku' => 'SKU-1', 'occurred_at' => '2026-04-01 10:00:00', 'quantity' => 9]);

        $march = $store->replay($tenant->id, '2026-03-01 00:00:00', '2026-03-31 23:59:59');
        $this->assertCount(2, $march, 'only the two March events replay');
        $this->assertSame('2026-03-01 10:00:00', $march->first()->occurred_at->format('Y-m-d H:i:s'));
    }

    public function test_append_is_idempotent_by_source_ref(): void
    {
        $tenant = $this->createTenant();
        $store  = app(EventStore::class);

        $store->append(['tenant_id' => $tenant->id, 'event_type' => PlatformEvent::TYPE_ACTION, 'source_ref' => 'action:99']);
        $store->append(['tenant_id' => $tenant->id, 'event_type' => PlatformEvent::TYPE_ACTION, 'source_ref' => 'action:99']);

        $this->assertSame(1, PlatformEvent::where('tenant_id', $tenant->id)->where('source_ref', 'action:99')->count());
    }

    public function test_action_creation_is_captured_live(): void
    {
        $tenant        = $this->createTenant();
        $investigation = Investigation::factory()->create(['tenant_id' => $tenant->id]);

        $action = Action::create([
            'investigation_id' => $investigation->id,
            'action_type'      => Action::TYPE_REORDER,
            'title'            => 'Reorder SKU-1',
            'status'           => Action::STATUS_UNASSIGNED,
            'priority'         => Action::PRIORITY_HIGH,
        ]);

        $event = PlatformEvent::where('tenant_id', $tenant->id)->where('source_ref', 'action:' . $action->id)->first();
        $this->assertNotNull($event, 'creating an action appends an action event');
        $this->assertSame(PlatformEvent::TYPE_ACTION, $event->event_type);
        $this->assertSame('Reorder SKU-1', $event->payload['title']);
    }

    public function test_outcome_capture_and_backfill_are_idempotent(): void
    {
        $tenant        = $this->createTenant();
        $investigation = Investigation::factory()->create(['tenant_id' => $tenant->id]);

        $outcome = InvestigationOutcome::create([
            'investigation_id'  => $investigation->id,
            'tenant_id'         => $tenant->id,
            'outcome_type'      => InvestigationOutcome::TYPE_RESOLVED,
            'observed_recovery' => 3200,
            'recorded_at'       => now(),
        ]);

        // Captured live on creation.
        $this->assertSame(1, PlatformEvent::where('source_ref', 'outcome:' . $outcome->id)->count());

        // Backfill finds it already captured → creates nothing new.
        Artisan::call('events:backfill', ['--tenant' => $tenant->id]);
        $this->assertSame(1, PlatformEvent::where('source_ref', 'outcome:' . $outcome->id)->count());
    }
}
