<?php

namespace Tests\Feature;

use App\Filament\Pages\AssortmentBenchmarks;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Assortment\AssortmentEngine;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Concerns\SeedsAssortmentEstate;
use Tests\TestCase;

/**
 * v2 — the benchmark explorer: a peer group's expected range per category
 * (core, expected, niche), one store against it, and the reasoning per product.
 */
class AssortmentBenchmarkTest extends TestCase
{
    use SeedsAssortmentEstate;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['apps' => [Tenant::APP_ROOT_CAUSE, Tenant::APP_ASSORTMENT], 'currency' => 'AED']);
        $this->admin = $this->actingAsTenantAdmin($this->tenant);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);

        $this->seedEstate();
        // A niche product: two of the six stores carry it.
        DB::table('products')->insert(['tenant_id' => $this->tenant->id, 'sku' => 'RARE', 'name' => 'Sheep yogurt', 'category' => 'Dairy',
            'subcategory' => 'Chilled', 'unit_cost' => 6, 'selling_price' => 10, 'created_at' => now(), 'updated_at' => now()]);
        $rows = $stock = [];
        for ($d = 0; $d < 200; $d++) {
            $date = Carbon::parse($this->asOf)->subDays($d)->toDateString();
            foreach ([2, 3] as $n) {
                $rows[] = ['tenant_id' => $this->tenant->id, 'store_id' => $this->stores[$n], 'sku' => 'RARE', 'date' => $date,
                    'units_sold' => 3, 'revenue' => 30, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
                $stock[] = ['tenant_id' => $this->tenant->id, 'store_id' => $this->stores[$n], 'sku' => 'RARE', 'on_hand_qty' => 20,
                    'as_of_date' => $date, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($rows, 1000) as $c) {
            DB::table('sales_daily')->insert($c);
        }
        foreach (array_chunk($stock, 1000) as $c) {
            DB::table('inventory_levels')->insert($c);
        }
        app(AssortmentEngine::class)->run($this->tenant);
    }

    public function test_the_expected_range_is_read_from_what_the_group_carries(): void
    {
        $this->assertSame('core', AssortmentBenchmarks::band(0.8));
        $this->assertSame('expected', AssortmentBenchmarks::band(0.5));
        $this->assertSame('niche', AssortmentBenchmarks::band(0.34));

        $this->get(AssortmentBenchmarks::getUrl(tenant: $this->tenant))->assertOk()
            ->assertSee('The expected range')->assertSee('Greek yogurt')->assertSee('5 of 6')->assertDontSee('Sheep yogurt');

        Livewire::test(AssortmentBenchmarks::class)
            ->assertSet('category', 'Dairy')
            ->assertViewHas('counts', ['core' => 12, 'expected' => 0, 'niche' => 1])
            ->set('show', 'niche')->assertSee('Sheep yogurt')->assertSee('2 of 6')
            ->call('focusOn', 'RARE')->assertSee('a local or optional choice')->assertSee('The engine proposes nothing for it in this group');
    }

    public function test_one_store_against_the_expected_range(): void
    {
        Livewire::test(AssortmentBenchmarks::class)
            ->set('store', (string) $this->stores[1])
            ->assertViewHas('coverage', fn ($c) => $c['expected'] === 12 && $c['carried'] === 11 && $c['extra'] === 0)
            ->assertSee('Not carried')->assertSee('Add proposed')
            ->call('focusOn', 'ADD-ME')->assertSee('Carried by')->assertSee('does not carry it')->assertSee('Open the add')
            ->set('store', '')->assertSet('store', null);
    }

    public function test_who_sees_it(): void
    {
        $user = $this->createUser($this->tenant);
        $user->forceFill(['visible_screens' => ['assortment_plans']])->save();
        $this->actingAs($user);
        $this->get(AssortmentBenchmarks::getUrl(tenant: $this->tenant))->assertForbidden();

        $user->forceFill(['visible_screens' => null])->save();
        $this->get(AssortmentBenchmarks::getUrl(tenant: $this->tenant))->assertOk()->assertSee('The expected range');
    }
}
