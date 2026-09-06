<?php

namespace Tests\Feature;

use App\Models\OutboundDispatch;
use App\Models\OutboundTarget;
use App\Platform\Integration\ActionIntent;
use App\Platform\Integration\OutboundDispatcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P2.1 — the outbound integration surface: a canonical action-intent dispatches
 * to a webhook target and records the round-trip; with no target it logs; and a
 * failed delivery is recorded, never thrown.
 */
class OutboundIntegrationTest extends TestCase
{
    public function test_webhook_target_dispatches_and_records_the_roundtrip(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $tenant = $this->createTenant();
        OutboundTarget::create([
            'tenant_id' => $tenant->id,
            'kind'      => OutboundTarget::KIND_WEBHOOK,
            'name'      => 'ERP hook',
            'endpoint'  => 'https://erp.example/hook',
            'active'    => true,
        ]);

        $intent = new ActionIntent(
            tenantId: $tenant->id,
            intentType: 'reorder',
            sku: 'SKU-1',
            quantity: 100,
            source: 'action:1',
        );

        $dispatch = app(OutboundDispatcher::class)->dispatch($intent);

        $this->assertSame(OutboundDispatch::STATUS_ACKNOWLEDGED, $dispatch->status);
        $this->assertSame(200, $dispatch->response_code);
        $this->assertSame('reorder', $dispatch->intent_type);
        Http::assertSent(fn ($request) => $request->url() === 'https://erp.example/hook'
            && $request['intent_type'] === 'reorder');
    }

    public function test_no_target_falls_back_to_the_log_connector(): void
    {
        Http::fake();
        $tenant = $this->createTenant();

        $intent = new ActionIntent(tenantId: $tenant->id, intentType: 'reorder', source: 'action:2');
        $dispatch = app(OutboundDispatcher::class)->dispatch($intent);

        $this->assertSame(OutboundDispatch::STATUS_SENT, $dispatch->status);
        $this->assertNull($dispatch->target_id);
        Http::assertNothingSent();
    }

    public function test_failed_delivery_is_recorded_not_thrown(): void
    {
        Http::fake(['*' => Http::response('upstream boom', 500)]);

        $tenant = $this->createTenant();
        OutboundTarget::create([
            'tenant_id' => $tenant->id,
            'kind'      => OutboundTarget::KIND_WEBHOOK,
            'endpoint'  => 'https://erp.example/hook',
            'active'    => true,
        ]);

        $intent = new ActionIntent(tenantId: $tenant->id, intentType: 'reorder', source: 'action:3');
        $dispatch = app(OutboundDispatcher::class)->dispatch($intent);

        $this->assertSame(OutboundDispatch::STATUS_FAILED, $dispatch->status);
        $this->assertSame(500, $dispatch->response_code);
    }
}
