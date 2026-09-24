<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Filament\Facades\Filament;
use PHPUnit\Framework\Attributes\DataProvider;
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
     */
    #[DataProvider('resourceProvider')]
    public function test_resource_index_page_does_not_500(string $resourceClass): void
    {
        // WP1.5: a renamed/removed class must FAIL, not silently skip.
        $this->assertTrue(class_exists($resourceClass), "$resourceClass not found — update the provider list");

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
     */
    #[DataProvider('pageProvider')]
    public function test_custom_page_does_not_500(string $pageClass): void
    {
        $this->assertTrue(class_exists($pageClass), "$pageClass not found — update the provider list");

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

    /**
     * WP1.5 (audit M27): the hand-maintained provider lists above had drifted —
     * 13 resources/pages were never smoke-tested (which is how agent-page 500s
     * shipped before). This walks EVERYTHING the admin panel actually registers,
     * and requires a real 200 — not merely "not a 500".
     */
    public function test_every_registered_admin_panel_route_renders(): void
    {
        $panel = Filament::getPanel('admin');
        $failures = [];
        // Super-admin-only screens: a tenant admin must get 403 (checked below).
        $superOnly = [\App\Filament\Resources\TenantResource::class, \App\Filament\Pages\UiKit::class];

        foreach ($panel->getResources() as $resource) {
            if (in_array($resource, $superOnly, true)) {
                continue;
            }
            if (! array_key_exists('index', $resource::getPages())) {
                continue;
            }
            $url = $resource::getUrl('index', ['tenant' => $this->tenant]);
            $status = $this->get($url)->baseResponse->getStatusCode();
            if ($status !== 200) {
                $failures[] = "$resource → HTTP $status ($url)";
            }
        }

        foreach ($panel->getPages() as $page) {
            if (in_array($page, $superOnly, true)) {
                continue;
            }
            $url = $page::getUrl(['tenant' => $this->tenant]);
            $status = $this->get($url)->baseResponse->getStatusCode();
            if ($status !== 200) {
                $failures[] = "$page → HTTP $status ($url)";
            }
        }

        $this->assertSame([], $failures, "Panel routes that do not render for a tenant admin:\n" . implode("\n", $failures));

        $this->get(\App\Filament\Resources\TenantResource::getUrl('index', ['tenant' => $this->tenant]))->assertForbidden();
        $this->get(\App\Filament\Pages\UiKit::getUrl(['tenant' => $this->tenant]))->assertForbidden();
    }

    /** Every Ops-console route must render for a super admin. */
    public function test_every_registered_ops_panel_route_renders(): void
    {
        auth()->logout(); // only the owner (or console) may mint a super admin
        $super = $this->createUser($this->tenant, superAdmin: true);
        $this->actingAs($super);
        $panel = Filament::getPanel('ops');
        Filament::setCurrentPanel($panel);
        $failures = [];

        foreach ($panel->getResources() as $resource) {
            if (! array_key_exists('index', $resource::getPages())) {
                continue;
            }
            $url = $resource::getUrl('index', panel: 'ops');
            $status = $this->get($url)->baseResponse->getStatusCode();
            if ($status !== 200) {
                $failures[] = "$resource → HTTP $status ($url)";
            }
        }
        foreach ($panel->getPages() as $page) {
            $url = $page::getUrl(panel: 'ops');
            if ($page === \App\Filament\Ops\Pages\TenantProfile::class) {
                $url .= '?tenant=' . $this->tenant->id; // record-style page: needs ?tenant=
            }
            $status = $this->get($url)->baseResponse->getStatusCode();
            if ($status !== 200) {
                $failures[] = "$page → HTTP $status ($url)";
            }
        }

        $this->assertSame([], $failures, "Ops routes that do not render for a super admin:\n" . implode("\n", $failures));
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

    /**
     * The Action Queue campaign drill-down (?campaign=) must render — the branch
     * the index smoke test never exercises, and where the AI plan panel + the
     * bulk-review controls live. Seeds one anomaly in a campaign so rows render.
     */
    public function test_action_queue_campaign_drilldown_does_not_500(): void
    {
        $investigation = \App\Models\Investigation::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'primary_sku' => 'SKU-AQ',
        ]);
        \App\Models\Anomaly::factory()->create([
            'tenant_id'        => $this->tenant->id,
            'investigation_id' => $investigation->id,
            'sku'              => 'SKU-AQ',
            'rule_type'        => 'demand_erosion',
        ]);

        \Livewire\Livewire::test(\App\Filament\Pages\ActionQueue::class)
            ->set('campaign', 'Demand erosion')
            ->assertOk();
    }

    /** The Action Queue "Today / what changed" tab must render (delta view). */
    public function test_action_queue_today_tab_does_not_500(): void
    {
        \App\Models\Investigation::factory()->create([
            'tenant_id' => $this->tenant->id,
            'opened_at' => now(),
        ]);

        \Livewire\Livewire::test(\App\Filament\Pages\ActionQueue::class)
            ->set('tab', 'today')
            ->assertOk();
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
                'ApiConnectionResource',
                'ApiKeyResource',
                'AuditLogResource',
                'ImportResource',
                'InventoryLevelResource',
                'InvestigationResource',
                'ProductResource',
                'PurchaseOrderResource',
                'SalesReturnResource',
                'SalesTransactionResource',
                'SftpConnectionResource',
                'StoreResource',
                'SupplierResource',
                'SuppressionResource',
                'TeamResource',
                'TeamsConnectionResource',
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
                'Account',
                'Dashboard',
                'ActionCenter',
                'ActionQueue',
                'DataHealthCenter',
                'WatchedInvestigations',
                'WeeklyBriefing',
                'FollowUps',
                'SupplierPrep',
                'DataHealth',
                'QualityCenter',
                'FinancialBreakdown',
                'Reports',
            ]
        );
    }
}
