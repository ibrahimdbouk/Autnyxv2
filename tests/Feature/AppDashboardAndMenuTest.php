<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\QualityCenter;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Apps\AppGate;
use App\Support\Apps\AppRegistry;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * One Dashboard with a switch between app dashboards, and a left menu grouped
 * by app: Dashboard first, one group per app, then the shared groups.
 */
class AppDashboardAndMenuTest extends TestCase
{
    private function enter(Tenant $tenant): User
    {
        $user = $this->actingAsTenantAdmin($tenant);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant);

        return $user;
    }

    /** Every page and resource of the tenant panel, as class names. */
    private function screens(): array
    {
        $panel = Filament::getPanel('admin');

        return array_values(array_unique([...$panel->getPages(), ...$panel->getResources()]));
    }

    public function test_every_screen_sits_in_its_apps_group_or_a_shared_group(): void
    {
        $this->enter($this->createTenant(['apps' => array_keys(Tenant::APP_LABELS)]));

        $bad = [];
        foreach ($this->screens() as $class) {
            if (! $class::shouldRegisterNavigation()) {
                continue; // drill-down pages never appear in the menu
            }
            $group = $class::getNavigationGroup();
            $app   = AppGate::appFor($class);

            if ($class === Dashboard::class) {
                if ($group !== null) {
                    $bad[] = "Dashboard must be ungrouped (the first item), got '{$group}'";
                }

                continue;
            }
            if ($group === null) {
                $bad[] = "{$class} has no menu group — only the Dashboard sits above the groups";

                continue;
            }
            if ($app !== null && $group !== AppRegistry::groupFor($app)) {
                $bad[] = "{$class} belongs to {$app} but is in '{$group}' (expected '" . AppRegistry::groupFor($app) . "')";
            }
            if ($app === null && ! in_array($group, AppRegistry::SHARED_GROUPS, true)) {
                $bad[] = "{$class} is shared but is in '{$group}' — use one of " . implode(', ', AppRegistry::SHARED_GROUPS) . ', or declare its app';
            }
        }

        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_the_panel_orders_app_groups_before_shared_groups(): void
    {
        $labels = array_map(fn ($g) => $g->getLabel(), Filament::getPanel('admin')->getNavigationGroups());

        $this->assertSame(AppRegistry::allGroups(), $labels);
    }

    public function test_the_dashboard_is_the_first_menu_item(): void
    {
        $this->enter($this->createTenant());

        $navigation = array_values(Filament::getPanel('admin')->getNavigation());
        $first      = $navigation[0];

        $this->assertNull($first->getLabel(), 'The first navigation block is the ungrouped one');
        $this->assertSame('Dashboard', $first->getItems()[0]->getLabel());
        $this->assertCount(1, $first->getItems(), 'Only the Dashboard sits above the groups');
    }

    public function test_a_single_app_tenant_sees_no_switch(): void
    {
        $tenant = $this->createTenant(['apps' => [Tenant::APP_ROOT_CAUSE]]);
        $this->enter($tenant);

        $this->get(Dashboard::getUrl(tenant: $tenant))
            ->assertOk()
            ->assertDontSee('Choose an app dashboard');
    }

    public function test_tabs_list_only_the_apps_the_tenant_holds(): void
    {
        $tenant = $this->createTenant(['apps' => [Tenant::APP_ROOT_CAUSE, Tenant::APP_ASSORTMENT]]);
        $this->enter($tenant);

        $this->get(Dashboard::getUrl(tenant: $tenant))
            ->assertOk()
            ->assertSee('Choose an app dashboard')
            ->assertSee('Assortment')
            ->assertDontSee('Tasks is being set up');
    }

    public function test_the_tab_follows_the_url_and_is_remembered(): void
    {
        $tenant = $this->createTenant(['apps' => [Tenant::APP_ROOT_CAUSE, Tenant::APP_ASSORTMENT]]);
        $user   = $this->enter($tenant);

        $this->get(Dashboard::getUrl(['app' => Tenant::APP_ASSORTMENT], tenant: $tenant))
            ->assertOk()
            ->assertSee('Assortment is being set up');

        $this->assertSame(Tenant::APP_ASSORTMENT, $user->fresh()->preferences['last_dashboard'] ?? null);

        // No ?app — the remembered tab opens.
        $this->get(Dashboard::getUrl(tenant: $tenant))->assertOk()->assertSee('Assortment is being set up');
    }

    public function test_an_app_the_tenant_does_not_hold_falls_back_to_the_default(): void
    {
        $tenant = $this->createTenant(['apps' => [Tenant::APP_ROOT_CAUSE]]);
        $this->enter($tenant);

        $this->get(Dashboard::getUrl(['app' => Tenant::APP_ASSORTMENT], tenant: $tenant))
            ->assertOk()
            ->assertDontSee('Assortment is being set up');
    }

    public function test_store_staff_land_on_tasks_first(): void
    {
        $tenant = $this->createTenant();
        $user   = User::factory()->make(['org_role' => User::ORG_STORE_MANAGER]);
        $all    = [Tenant::APP_ROOT_CAUSE, Tenant::APP_TASK_EXECUTION];

        $this->assertSame(Tenant::APP_TASK_EXECUTION, AppRegistry::defaultFor($user, $all));
        $this->assertSame(Tenant::APP_ROOT_CAUSE, AppRegistry::defaultFor($user, [Tenant::APP_ROOT_CAUSE]));
        $this->assertSame(Tenant::APP_ROOT_CAUSE, AppRegistry::defaultFor(User::factory()->make(), $all));
    }

    public function test_a_revoked_app_hides_its_screens_and_refuses_them(): void
    {
        $tenant = $this->createTenant(['apps' => [Tenant::APP_TASK_EXECUTION]]);
        $this->enter($tenant);

        $groups = array_map(fn ($g) => $g->getLabel(), Filament::getPanel('admin')->getNavigation());
        $this->assertNotContains(AppRegistry::GROUP_ROOT_CAUSE, $groups, 'Root Cause leaves the menu when the tenant does not hold it');
        $this->assertContains('Data', $groups, 'Shared groups stay');
        $this->get(QualityCenter::getUrl(tenant: $tenant))->assertForbidden();
        $this->get(Dashboard::getUrl(tenant: $tenant))->assertOk()->assertSee('Tasks is being set up');
    }

    public function test_the_sidebar_opens_the_current_apps_group_and_closes_the_others(): void
    {
        $script = AppRegistry::sidebarScript(Tenant::APP_ROOT_CAUSE);

        $this->assertStringContainsString('"open":"Root Cause"', $script);
        $this->assertStringContainsString('"Assortment"', $script);
        $this->assertStringContainsString('"Tasks"', $script);
        $this->assertSame('', AppRegistry::sidebarScript(null), 'Shared pages leave the groups alone');
    }
}
