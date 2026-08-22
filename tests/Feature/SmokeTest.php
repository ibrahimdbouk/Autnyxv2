<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Filament\Facades\Filament;
use Tests\TestCase;
use Throwable;

/**
 * Route smoke tests — the second line of defence against 500s.
 *
 * For every Filament panel page a logged-in tenant admin can reach, this
 * boots the page and asserts the HTTP status is NOT a server error (>= 500).
 * It does not assert *correctness* — only that the page renders without a
 * fatal. That is exactly the class of bug (INC-001..003) that has been
 * hitting production: pages that throw at render time.
 *
 * Runs against PostgreSQL in CI (see .github/workflows/ci.yml) so that
 * Postgres-only SQL (TO_CHAR, etc.) is exercised faithfully.
 *
 * The authoritative compile gate is the Laravel Cloud build (view:cache +
 * filament:cache-components via composer's compile-gate script). This suite
 * catches the render-time 500s that a pure compile check cannot see.
 */
class SmokeTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // A tenant + an admin that belongs to it, acting inside the admin panel.
        $this->tenant = $this->createTenant();
        $this->actingAsTenantAdmin($this->tenant);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);
    }

    /**
     * Every auto-discovered Filament Resource: its index (list) page.
     *
     * @dataProvider resourceProvider
     */
    public function test_resource_index_page_does_not_500(string $resourceClass): void
    {
        if (!class_exists($resourceClass)) {
            $this->markTestSkipped("$resourceClass not found");
        }

        try {
            $url = $resourceClass::getUrl('index', ['tenant' => $this->tenant]);
        } catch (Throwable $e) {
            $this->fail("Could not build index URL for $resourceClass: {$e->getMessage()}");
        }

        $status = $this->get($url)->baseResponse->getStatusCode();

        $this->assertLessThan(
            500,
            $status,
            "$resourceClass index page returned HTTP $status (server error) at $url"
        );
    }

    /**
     * Every custom Filament Page.
     *
     * @dataProvider pageProvider
     */
    public function test_custom_page_does_not_500(string $pageClass): void
    {
        if (!class_exists($pageClass)) {
            $this->markTestSkipped("$pageClass not found");
        }

        try {
            $url = $pageClass::getUrl(['tenant' => $this->tenant]);
        } catch (Throwable $e) {
            $this->fail("Could not build URL for $pageClass: {$e->getMessage()}");
        }

        $status = $this->get($url)->baseResponse->getStatusCode();

        $this->assertLessThan(
            500,
            $status,
            "$pageClass returned HTTP $status (server error) at $url"
        );
    }

    /** The public landing route must render. */
    public function test_root_route_does_not_500(): void
    {
        $status = $this->get('/')->baseResponse->getStatusCode();
        $this->assertLessThan(500, $status, "/ returned HTTP $status");
    }

    /**
     * Record-bound investigate pages must render WITH a real record.
     *
     * The index smoke tests never exercise these — they need a {record}. This
     * seeds one linked anomaly + investigation and boots both investigate pages.
     * Regression guard for the count()-collision and bigint route-binding 500s.
     */
    public function test_investigate_record_pages_do_not_500(): void
    {
        $investigation = \App\Models\Investigation::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'primary_sku' => 'SKU-SMOKE',
        ]);
        $anomaly = \App\Models\Anomaly::factory()->create([
            'tenant_id'        => $this->tenant->id,
            'investigation_id' => $investigation->id,
            'sku'              => 'SKU-SMOKE',
        ]);

        $invUrl = \App\Filament\Resources\InvestigationResource::getUrl(
            'investigate',
            ['record' => $investigation->id, 'tenant' => $this->tenant]
        );
        $this->assertLessThan(
            500,
            $this->get($invUrl)->baseResponse->getStatusCode(),
            "InvestigateInvestigation page 500'd at $invUrl"
        );

        $anomUrl = \App\Filament\Resources\AnomalyResource::getUrl(
            'investigate',
            ['record' => $anomaly->id, 'tenant' => $this->tenant]
        );
        $this->assertLessThan(
            500,
            $this->get($anomUrl)->baseResponse->getStatusCode(),
            "InvestigateAnomaly page 500'd at $anomUrl"
        );
    }

    /**
     * The investigation list page must render both empty AND with a row selected
     * in the side panel (the state that exposed the $selected count() collision).
     */
    public function test_investigation_list_with_selection_does_not_500(): void
    {
        $investigation = \App\Models\Investigation::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        \Livewire\Livewire::test(\App\Filament\Resources\InvestigationResource\Pages\ListInvestigations::class)
            ->call('selectInvestigation', $investigation->id)
            ->assertOk();
    }

    /**
     * The financial drill-down page must render for EVERY supported metric,
     * both on an empty tenant and with a recorded outcome contributing a figure.
     */
    public function test_financial_breakdown_all_metrics_do_not_500(): void
    {
        // Empty tenant — every metric renders with zeros.
        foreach (\App\Filament\Pages\FinancialBreakdown::METRICS as $metric) {
            \Livewire\Livewire::test(\App\Filament\Pages\FinancialBreakdown::class)
                ->set('metric', $metric)
                ->assertOk();
        }

        // With a resolved investigation + recorded outcome, so the row lists,
        // formula and component figures all exercise real data.
        $investigation = \App\Models\Investigation::factory()->create([
            'tenant_id'       => $this->tenant->id,
            'status'          => \App\Models\Investigation::STATUS_RESOLVED,
            'primary_sku'     => 'SKU-FIN',
            'revenue_at_risk' => 5000,
        ]);
        \App\Models\InvestigationOutcome::create([
            'investigation_id'   => $investigation->id,
            'tenant_id'          => $this->tenant->id,
            'revenue_at_risk'    => 5000,
            'observed_recovery'  => 3200,
            'was_false_positive' => false,
            'recovery_method'    => 'markdown_recovery',
            'recorded_at'        => now(),
        ]);

        foreach (\App\Filament\Pages\FinancialBreakdown::METRICS as $metric) {
            \Livewire\Livewire::test(\App\Filament\Pages\FinancialBreakdown::class)
                ->set('metric', $metric)
                ->assertOk();
        }
    }

    /* ---------- data providers ---------------------------------------- */

    public static function resourceProvider(): array
    {
        $ns = 'App\\Filament\\Resources\\';
        return array_map(
            fn ($c) => [$ns . $c],
            [
                'AnomalyResource',
                'AnomalySettingResource',
                'AuditLogResource',
                'ImportResource',
                'InventoryLevelResource',
                'InvestigationResource',
                'ProductResource',
                'PurchaseOrderResource',
                'SalesTransactionResource',
                'SftpConnectionResource',
                'StoreResource',
                'SuppressionResource',
                'TeamResource',
                'TenantResource',
                'UserResource',
            ]
        );
    }

    public static function pageProvider(): array
    {
        $ns = 'App\\Filament\\Pages\\';
        return array_map(
            fn ($c) => [$ns . $c],
            [
                'Dashboard',
                'ActionCenter',
                'DataHealthCenter',
                'WatchedInvestigations',
                'QualityCenter',
                'FinancialBreakdown',
                'Reports',
            ]
        );
    }
}
