<?php

namespace Tests\Feature;

use App\Filament\Pages\ActionQueue;
use App\Filament\Pages\DailyBriefing;
use App\Filament\Pages\InvestigationsByStore;
use App\Filament\Pages\WeeklyBriefing;
use App\Filament\Resources\AnomalySettingResource;
use App\Filament\Resources\InvestigationResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Apps\AppRegistry;
use App\Support\Apps\MenuSets;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * v2.5 — the shortened Root Cause menu: screens that belong together sit behind
 * one entry, and show as tabs along the top of each. URLs, permissions and
 * screen keys are unchanged.
 */
class RootCauseMenuTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        MenuSets::flush();
    }

    private function as(?User $user = null): void
    {
        $user ? $this->actingAs($user) : $this->actingAsTenantAdmin($this->tenant);
        Filament::setTenant($this->tenant);
    }

    /** @return list<string> the Root Cause menu entries the current person sees */
    private function rootCauseMenu(): array
    {
        $panel = Filament::getPanel('admin');
        foreach ($panel->getNavigation() as $group) {
            if ($group->getLabel() === AppRegistry::GROUP_ROOT_CAUSE) {
                return array_map(fn ($i) => (string) $i->getLabel(), array_values($group->getItems()->all()));
            }
        }

        return [];
    }

    public function test_the_menu_has_one_entry_per_set(): void
    {
        $this->as();
        $menu = $this->rootCauseMenu();

        foreach (['Briefings', 'Investigations', 'Actions', 'Replenishment', 'Suppliers', 'Detection settings', 'Anomalies', 'Reports'] as $label) {
            $this->assertContains($label, $menu);
        }
        foreach (['Daily Briefing', 'Weekly Briefing', 'By Store', 'My Watched', 'Action Queue', 'Follow-Ups', 'Supplier Prep', 'Suppressions', 'Custom rules', 'Custom KPIs', 'Planning Exceptions', 'Performance drivers'] as $gone) {
            $this->assertNotContains($gone, $menu, $gone . ' now sits behind its set');
        }
        $this->assertLessThanOrEqual(11, count($menu));
    }

    public function test_each_screen_shows_its_set_as_tabs_and_keeps_its_url(): void
    {
        $this->as();
        $tabs = MenuSets::tabs('actions', ActionQueue::class);
        $this->assertSame(['Action Center', 'Action Queue', 'Follow-Ups'], array_map(fn ($t) => (string) $t->getLabel(), $tabs));
        $this->assertSame([false, true, false], array_map(fn ($t) => $t->isActive(), $tabs));

        $this->get(InvestigationsByStore::getUrl(tenant: $this->tenant))->assertOk()
            ->assertSee('My Watched')->assertSee('fi-tabs', false);
        $this->get(InvestigationResource::getUrl('index', tenant: $this->tenant))->assertOk()->assertSee('By Store');
        $this->get(AnomalySettingResource::getUrl('index', tenant: $this->tenant))->assertOk()->assertSee('Custom KPIs');
        $this->get(DailyBriefing::getUrl(tenant: $this->tenant))->assertOk()->assertSee('Weekly Briefing');
        $this->get(\App\Filament\Pages\PerformanceDrivers::getUrl(tenant: $this->tenant))->assertOk()
            ->assertSee('Daily Briefing')->assertSee('fi-tabs', false);
    }

    public function test_a_person_sees_only_the_screens_they_may_open(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'visible_screens' => ['weekly_briefing']]);
        $this->as($user);

        $this->assertSame([WeeklyBriefing::class], MenuSets::open('briefings'));
        $this->assertSame(WeeklyBriefing::class, MenuSets::lead('briefings'), 'the entry leads to the screen they may open');
        $this->assertSame([], MenuSets::tabs('briefings', WeeklyBriefing::class), 'one screen: no tabs');
        $this->assertContains('Briefings', $this->rootCauseMenu());
        $this->assertNotContains('Actions', $this->rootCauseMenu(), 'no screen of a set: no entry');
        $this->assertSame([], DailyBriefing::getNavigationItems());
    }
}
