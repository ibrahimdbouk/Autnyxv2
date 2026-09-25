<?php

namespace Tests\Feature;

use App\Filament\Pages\DataHealthCenter;
use App\Models\AnomalySetting;
use App\Models\AutonomyPolicy;
use App\Models\OutboundDispatch;
use App\Models\OutboundTarget;
use App\Models\Tenant;
use App\Platform\Integration\ActionIntent;
use App\Platform\Integration\OutboundDispatcher;
use App\Platform\Orchestration\AutonomyRegistry;
use App\Services\Ops\TenantProvisioner;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WP7.4 (audit L6–L14, D12) — entitlements that decide access, recommend-only
 * unless a tenant opts in, rule settings that follow code defaults, one Data
 * Health screen, new tenants on the corrected rules.
 */
class CleanupAndGuardsTest extends TestCase
{
    public function test_a_tenant_without_root_cause_cannot_open_its_screens(): void
    {
        $t = $this->createTenant(['apps' => [Tenant::APP_ASSORTMENT]]);
        $this->actingAsTenantAdmin($t);

        $this->get(\App\Filament\Resources\AnomalyResource::getUrl('index', ['tenant' => $t]))->assertForbidden();
        $this->get(\App\Filament\Resources\InvestigationResource::getUrl('index', ['tenant' => $t]))->assertForbidden();
        $this->get(\App\Filament\Pages\ActionCenter::getUrl(['tenant' => $t]))->assertForbidden();
        $this->get(\App\Filament\Pages\FinancialBreakdown::getUrl(['tenant' => $t]))->assertForbidden();
        $this->get(\App\Filament\Resources\ProductResource::getUrl('index', ['tenant' => $t]))->assertOk();   // shared data
        $this->get(\App\Filament\Pages\Dashboard::getUrl(['tenant' => $t]))->assertOk();
    }

    public function test_a_root_cause_tenant_opens_them(): void
    {
        $rc = $this->createTenant();   // no apps set = Root-Cause
        $this->actingAsTenantAdmin($rc);
        $this->get(\App\Filament\Resources\AnomalyResource::getUrl('index', ['tenant' => $rc]))->assertOk();
    }

    private function target(Tenant $t): void
    {
        OutboundTarget::create(['tenant_id' => $t->id, 'kind' => OutboundTarget::KIND_WEBHOOK, 'name' => 'ERP',
            'endpoint' => 'https://erp.example/hook', 'active' => true]);
    }

    private function intent(Tenant $t): ActionIntent
    {
        return new ActionIntent(tenantId: $t->id, intentType: 'reorder', sku: 'A', quantity: 10, rationale: 'x', source: 'test');
    }

    public function test_nothing_is_sent_to_a_tenant_system_without_an_explicit_opt_in(): void
    {
        Http::fake(['erp.example/*' => Http::response(['ok' => true], 200)]);
        $t = $this->createTenant();
        $this->target($t);
        app(AutonomyRegistry::class)->define($t->id, AutonomyPolicy::LEVEL_AUTO);

        $d = app(OutboundDispatcher::class)->dispatch($this->intent($t));
        $this->assertSame(OutboundDispatch::STATUS_HELD, $d->status);
        $this->assertStringContainsString('opted in', $d->response_body);
        Http::assertNothingSent();

        $t->update(['settings' => ['autonomy' => ['execution_opt_in' => true]]]);
        app(AutonomyRegistry::class)->define($t->id, AutonomyPolicy::LEVEL_ADVISE);
        $this->assertSame(OutboundDispatch::STATUS_HELD, app(OutboundDispatcher::class)->dispatch($this->intent($t))->status, 'advise-only');
        Http::assertNothingSent();

        app(AutonomyRegistry::class)->define($t->id, AutonomyPolicy::LEVEL_APPROVE);
        $this->assertNotSame(OutboundDispatch::STATUS_HELD, app(OutboundDispatcher::class)->dispatch($this->intent($t))->status);
        Http::assertSentCount(1);
    }

    public function test_auto_level_orchestration_only_recommends_without_opt_in(): void
    {
        Http::fake();
        $t = $this->createTenant();
        $this->target($t);
        app(AutonomyRegistry::class)->define($t->id, AutonomyPolicy::LEVEL_AUTO, minConfidence: 0.5);

        $outcome = app(\App\Platform\Orchestration\OrchestrationPipeline::class)->orchestrate(new \App\Platform\Recommendation\Recommendation(
            tenantId: $t->id, intentType: 'reorder', sku: 'SKU-1', quantity: 5, expectedValue: 500, confidence: 0.95, risk: 0.1, source: 'sig:1',
        ));

        $this->assertSame(\App\Platform\Orchestration\OrchestrationOutcome::MODE_ADVISED, $outcome->mode);
        $this->assertStringContainsString('held', implode(' ', $outcome->reasons));
        Http::assertNothingSent();
    }

    public function test_the_log_connector_still_records_without_opt_in(): void
    {
        $t = $this->createTenant();
        $d = app(OutboundDispatcher::class)->dispatch($this->intent($t));
        $this->assertNotSame(OutboundDispatch::STATUS_HELD, $d->status, 'no target: recorded, nothing leaves');
    }

    public function test_rule_settings_follow_code_defaults_unless_overridden(): void
    {
        $t = $this->createTenant();
        $current = AnomalySetting::RULES['demand_erosion']['default_thresholds']['min_revenue'];
        // A row seeded before WP7.4: a copy of the old default, never reconciled.
        $old = AnomalySetting::withoutEvents(fn () => AnomalySetting::create(['tenant_id' => $t->id, 'rule_type' => 'demand_erosion',
            'enabled' => true, 'thresholds' => ['min_revenue' => 500, 'pct' => 99], 'settings_version' => 0]));

        $this->assertEquals($current, $old->getEffectiveThresholds()['min_revenue'], 'a seeded old default is not a choice');
        $this->assertEquals(99, $old->getEffectiveThresholds()['pct'], 'a real override stays');

        $this->artisan('anomaly-settings:sync')->assertSuccessful();
        $this->assertSame(0, $old->fresh()->settings_version, 'dry run by default');
        $this->artisan('anomaly-settings:sync --apply')->assertSuccessful();
        $fresh = $old->fresh();
        $this->assertSame(['pct' => 99], $fresh->thresholds);
        $this->assertSame(AnomalySetting::SETTINGS_VERSION, $fresh->settings_version);

        AnomalySetting::seedForTenant($t->id);
        $seeded = AnomalySetting::where('tenant_id', $t->id)->where('rule_type', 'sales_drop')->first();
        $this->assertNull($seeded->thresholds, 'new rows store overrides only');
        $seeded->update(['thresholds' => AnomalySetting::RULES['sales_drop']['default_thresholds']]);
        $this->assertNull($seeded->fresh()->thresholds, 'saving the defaults is not an override');
    }

    public function test_data_health_is_the_one_status_screen_and_old_links_land_on_it(): void
    {
        $t = $this->createTenant();
        $this->actingAsTenantAdmin($t);

        $this->get(DataHealthCenter::getUrl(['tenant' => $t]))->assertOk()
            ->assertSee('id="readiness"', false)->assertSee('id="ai-check"', false)->assertSee('Dataset health');
        $this->get('/admin/' . $t->slug . '/data-readiness')->assertRedirect('/admin/' . $t->slug . '/data-health#readiness');
        $this->get('/admin/' . $t->slug . '/data-quality')->assertRedirect('/admin/' . $t->slug . '/data-health#ai-check');

        $viewer = $this->createUser($t);
        $viewer->forceFill(['visible_screens' => ['data_quality']])->save();
        $this->actingAs($viewer);
        $this->get(DataHealthCenter::getUrl(['tenant' => $t]))->assertOk()
            ->assertSee('id="ai-check"', false)->assertDontSee('Dataset health')->assertDontSee('id="readiness"', false);
    }

    public function test_new_tenants_start_on_the_corrected_rules_in_their_timezone(): void
    {
        $t = app(TenantProvisioner::class)->create(['name' => 'Gamma', 'timezone' => 'Asia/Riyadh']);

        $this->assertTrue(\App\Services\Anomaly\AnomalyDetectionService::rulesV2For($t->id));
        $this->assertSame('Asia/Riyadh', $t->fresh()->timezone);
        $this->assertFalse($t->executionOptedIn(), 'recommend-only by default');
    }
}
