<?php

namespace Tests\Feature;

use App\Filament\Pages\ActionCenter;
use App\Filament\Pages\FinancialBreakdown;
use App\Filament\Resources\InvestigationResource\Pages\ListInvestigations;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WP7.2 (audit M8) — URL-bound page state is input: crafted links fall back
 * to the defaults instead of a 500, and a Livewire call with a wrongly typed
 * argument is a 400.
 */
class InputHardeningTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['status' => 'active']);
        $this->actingAs($this->createUser($this->tenant, admin: true));
    }

    public function test_crafted_links_open_the_page_with_safe_defaults(): void
    {
        $base = '/admin/' . $this->tenant->slug;
        foreach ([
            '/investigations?store=abc', '/investigations?team=zz', '/investigations?from=yesterday',
            '/investigations?min[]=1', '/investigations?status[]=x', '/investigations?q[a]=b',
            '/action-center?tab[]=x', '/action-center?assignee=abc', '/action-center?priority[]=x',
            '/financial-breakdown?metric=nope', '/financial-breakdown?metric[]=x',
        ] as $url) {
            $this->get($base . $url)->assertOk();
        }
    }

    public function test_values_are_cleaned_before_they_reach_a_query(): void
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        Filament::setTenant($this->tenant);
        $panel->boot();

        $lw = Livewire::withQueryParams(['store' => 'abc', 'from' => '2026-02-30', 'status' => 'bogus', 'min' => '12.5'])
            ->test(ListInvestigations::class);
        $this->assertSame('', $lw->get('storeFilter'));
        $this->assertSame('', $lw->get('openedFrom'), 'Feb 30 is not a date');
        $this->assertSame('open', $lw->get('statusFilter'));
        $this->assertSame('12.5', $lw->get('minValue'));

        $lw->set('storeFilter', ['x'])->assertOk();
        $this->assertSame('', $lw->get('storeFilter'));
        $lw->set('storeFilter', '42');
        $this->assertSame('42', $lw->get('storeFilter'));

        $this->assertSame('all', Livewire::withQueryParams(['tab' => 'nope'])->test(ActionCenter::class)->get('activeTab'));
        $this->assertSame('revenue_at_risk', Livewire::withQueryParams(['metric' => 'nope'])->test(FinancialBreakdown::class)->get('metric'));
    }

    public function test_a_wrongly_typed_livewire_call_is_a_bad_request(): void
    {
        Route::post('/livewire-test/update', fn () => (fn (int $id) => $id)(['x']))->middleware('web');

        $this->withHeader('X-Livewire', '1')->post('/livewire-test/update')->assertStatus(400);
    }
}
