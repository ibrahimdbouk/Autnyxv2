<?php

namespace Tests\Feature;

use App\Filament\Resources\WebhookEndpointResource\Pages\CreateWebhookEndpoint;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\CycleCount;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Counts\CycleCountService;
use App\Services\Webhooks\WebhookDispatcher;
use App\Services\Webhooks\WebhookEvents;
use App\Support\Http\HostResolver;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * W13 — outbound webhooks: signed, logged, retried, notification only.
 */
class WebhooksTest extends TestCase
{
    private Tenant $tenant;
    private WebhookEndpoint $hook;

    /** @var array<int, Request> */
    private array $received = [];

    private bool $down = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(HostResolver::class, new class extends HostResolver {
            public function resolve(string $host): array
            {
                return ['52.10.20.30'];
            }
        });
        $this->tenant = $this->createTenant();
        $this->hook = WebhookEndpoint::create(['tenant_id' => $this->tenant->id, 'name' => 'Flow', 'url' => 'https://hooks.example.com/in',
            'secret' => 'whsec_test', 'events' => ['investigation.opened', 'investigation.resolved', 'outcome.measured', 'count.recorded'], 'active' => true]);
        WebhookEvents::forget();
        Http::fake(function (Request $r) {
            $this->received[] = $r;

            return $this->down ? Http::response('down', 503) : Http::response(['ok' => true], 200);
        });
    }

    private function events(): array
    {
        return array_map(fn (Request $r) => $r->header('X-Autnyx-Event')[0] ?? null, $this->received);
    }

    public function test_changes_become_signed_events_for_subscribed_endpoints_only(): void
    {
        $inv = Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'title' => 'Water stock-out', 'status' => Investigation::STATUS_OPEN,
            'revenue_at_risk' => 900]);
        $inv->update(['priority' => 'high']);                                        // not an event
        $inv->update(['status' => Investigation::STATUS_RESOLVED, 'resolved_at' => now()]);
        InvestigationOutcome::create(['tenant_id' => $this->tenant->id, 'investigation_id' => $inv->id, 'measured_recovery' => 450, 'recorded_at' => now()]);

        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Marina', 'code' => 'MAR']);
        $count = CycleCount::create(['tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => 'W1', 'reason' => 'phantom_inventory',
            'system_qty' => 10, 'unit_cost' => 2, 'value_at_risk' => 20, 'rank' => 1, 'status' => CycleCount::STATUS_OPEN]);
        app(CycleCountService::class)->record($count, 4);

        // Another tenant's changes never reach this endpoint.
        Investigation::factory()->create(['tenant_id' => $this->createTenant()->id]);

        $this->assertSame(['investigation.opened', 'investigation.resolved', 'outcome.measured', 'count.recorded'], $this->events());

        $first = $this->received[0];
        $body = $first->body();
        preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $first->header('X-Autnyx-Signature')[0], $m);
        $this->assertSame(hash_hmac('sha256', $m[1] . '.' . $body, 'whsec_test'), $m[2], 'the receiver can verify the signature');
        $payload = json_decode($body, true);
        $this->assertSame('investigation.opened', $payload['event']);
        $this->assertSame('Water stock-out', $payload['data']['title']);
        $this->assertSame(900.0, (float) $payload['data']['revenue_at_risk']);
        $this->assertSame(-6.0, (float) json_decode($this->received[3]->body(), true)['data']['variance_qty']);

        $this->assertSame(4, WebhookDelivery::where('webhook_endpoint_id', $this->hook->id)->where('status', 'delivered')->count());
    }

    public function test_new_findings_are_swept_from_the_endpoints_cursor(): void
    {
        $this->hook->update(['events' => ['finding.opened'], 'min_severity' => 'medium']);
        $mk = fn (string $sku, string $sev) => Anomaly::create(['tenant_id' => $this->tenant->id, 'rule_type' => 'stockout_risk', 'severity' => $sev,
            'sku' => $sku, 'description' => "Stock-out {$sku}", 'context' => ['revenue_impact' => 100], 'detected_at' => now()]);
        $mk('OLD', 'high');

        $this->artisan('webhooks:findings', ['--tenant' => $this->tenant->id])->assertSuccessful();
        $this->assertSame([], $this->events(), 'a new endpoint starts from now, not with the history');

        $mk('A', 'high');
        $mk('B', 'low');
        $mk('C', 'medium');
        $this->artisan('webhooks:findings', ['--tenant' => $this->tenant->id])->assertSuccessful();
        $skus = array_map(fn (Request $r) => json_decode($r->body(), true)['data']['sku'], $this->received);
        $this->assertSame(['A', 'C'], $skus);

        $this->artisan('webhooks:findings', ['--tenant' => $this->tenant->id])->assertSuccessful();
        $this->assertCount(2, $this->received, 'each finding is sent once');
    }

    public function test_failures_retry_then_give_up_and_a_dead_endpoint_is_switched_off(): void
    {
        $this->down = true;
        $w = app(WebhookDispatcher::class);

        $d = $w->queue($this->hook, 'ping', ['x' => 1], dispatch: false);
        (new DeliverWebhookJob($d->id))->handle($w);
        $d->refresh();
        $this->assertSame([WebhookDelivery::STATUS_PENDING, 1, 503], [$d->status, $d->attempts, $d->response_status]);
        $this->assertTrue($d->next_attempt_at->gt(now()), 'the retry is scheduled');

        $d->forceFill(['attempts' => 5])->save();
        (new DeliverWebhookJob($d->id))->handle($w);
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $d->fresh()->status);
        $this->assertSame(1, $this->hook->fresh()->failure_streak);

        $this->hook->forceFill(['failure_streak' => WebhookEndpoint::DISABLE_AFTER - 1])->save();
        $d2 = $w->queue($this->hook->fresh(), 'ping', [], dispatch: false);
        $d2->forceFill(['attempts' => 5])->save();
        (new DeliverWebhookJob($d2->id))->handle($w);
        $this->assertFalse($this->hook->fresh()->active);
        $this->assertStringContainsString('failed deliveries in a row', (string) $this->hook->fresh()->disabled_reason);
        $this->assertTrue(AuditLog::where('tenant_id', $this->tenant->id)->where('event_type', 'webhook_disabled')->exists());
    }

    public function test_an_internal_address_is_never_called(): void
    {
        $this->hook->forceFill(['url' => 'https://localhost/in'])->save();
        $d = app(WebhookDispatcher::class)->queue($this->hook, 'ping', [], dispatch: false);
        $this->assertFalse(app(WebhookDispatcher::class)->attempt($d));
        $this->assertStringContainsString('Blocked', (string) $d->fresh()->response_excerpt);
        $this->assertSame([], $this->received);
    }

    public function test_admins_add_a_webhook_and_get_its_secret_once(): void
    {
        $this->actingAsTenantAdmin($this->tenant);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);

        Livewire::test(CreateWebhookEndpoint::class)
            ->fillForm(['name' => 'Zapier', 'url' => 'https://hooks.example.com/zap', 'events' => ['investigation.opened'], 'min_severity' => 'high', 'active' => true])
            ->call('create')->assertHasNoFormErrors()->assertNotified('Webhook added — signing secret');

        $hook = WebhookEndpoint::where('name', 'Zapier')->sole();
        $this->assertSame($this->tenant->id, $hook->tenant_id);
        $this->assertStringStartsWith('whsec_', $hook->secret);
        $this->assertNotSame($hook->secret, $hook->getRawOriginal('secret'), 'stored encrypted');

        Livewire::test(CreateWebhookEndpoint::class)
            ->fillForm(['name' => 'Bad', 'url' => 'http://10.0.0.5/x', 'events' => ['investigation.opened'], 'min_severity' => 'high'])
            ->call('create')->assertHasFormErrors(['url']);
    }
}
