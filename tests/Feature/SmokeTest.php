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

    /* ---------- data providers ---------------------------------------- */

    public static function resourceProvider(): array
    {
        $ns = 'App\\Filament\\Resources\\';
        return array_map(
            fn ($c) => [$ns . $c],
            [
                'AnomalyResource',
                'AnomalySettingResource',
                'ImportResource',
                'InventoryLevelResource',
                'InvestigationResource',
                'ProductResource',
                'PurchaseOrderResource',
                'SalesTransactionResource',
                'StoreResource',
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
            ]
        );
    }
}
