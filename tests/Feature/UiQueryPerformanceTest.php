<?php

namespace Tests\Feature;

use App\Filament\Pages\DataHealthCenter;
use App\Filament\Pages\InvestigationsByStore;
use App\Filament\Resources\SalesTransactionResource\Pages\ListSalesTransactions;
use App\Filament\Resources\StoreResource\Pages\ListStores;
use App\Jobs\DataHealth\ComputeDataHealthJob;
use App\Models\DataHealthSnapshot;
use App\Models\Investigation;
use App\Models\SalesTransaction;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\DataHealth\DataHealthService;
use App\Support\Database\TableStats;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WP6.4 (audit H32, M10) — pages that stay fast on a big tenant: fact lists
 * without COUNT(*) or DISTINCT scans and with a default window, store counts
 * from indexes, a cached dashboard, Data Health off the request, a per-store
 * window query, usage counts from statistics.
 */
class UiQueryPerformanceTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['status' => 'active']);
        $this->actingAs($this->createUser($this->tenant, admin: true));
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        Filament::setTenant($this->tenant);
        $panel->boot();
    }

    public function test_the_sales_list_opens_on_the_last_30_days_and_searches_stores_through_the_master(): void
    {
        $mall = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Marina Mall', 'code' => 'MM1']);
        $old  = SalesTransaction::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $mall->id, 'transaction_id' => 'OLD-1', 'date' => now()->subDays(60)->toDateString()]);
        $new  = SalesTransaction::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => $mall->id, 'transaction_id' => 'NEW-1', 'date' => now()->subDays(2)->toDateString()]);
        $else = SalesTransaction::factory()->create(['tenant_id' => $this->tenant->id, 'store_id' => null, 'transaction_id' => 'NEW-2', 'date' => now()->subDays(2)->toDateString()]);

        Livewire::test(ListSalesTransactions::class)
            ->assertCanSeeTableRecords([$new, $else])
            ->assertCanNotSeeTableRecords([$old])
            ->searchTable('marina')
            ->assertCanSeeTableRecords([$new])
            ->assertCanNotSeeTableRecords([$else]);

        Livewire::test(ListSalesTransactions::class)
            ->searchTable('NEW-2')
            ->assertCanSeeTableRecords([$else])
            ->assertCanNotSeeTableRecords([$new]);
    }

    public function test_the_store_list_shows_the_last_sale_and_stock_positions(): void
    {
        $mall = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Marina Mall']);
        DB::table('sales_daily')->insert(['tenant_id' => $this->tenant->id, 'store_id' => $mall->id, 'sku' => 'A', 'date' => '2026-09-20',
            'units_sold' => 1, 'revenue' => 1, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        \App\Models\InventoryLevel::create(['tenant_id' => $this->tenant->id, 'store_id' => $mall->id, 'sku' => 'A', 'on_hand_qty' => 3, 'as_of_date' => '2026-09-20']);

        Livewire::test(ListStores::class)
            ->assertCanSeeTableRecords([$mall])
            ->assertTableColumnStateSet('current_positions_count', 1, $mall)
            ->assertSee('Sep 20, 2026');
    }

    public function test_dashboard_figures_are_cached_per_tenant(): void
    {
        $widget = new \App\Filament\Widgets\PoFulfillmentStatsWidget();
        (fn () => $this->getStats())->call($widget);

        $this->assertTrue(Cache::has('dash:' . $this->tenant->id . ':PoFulfillmentStatsWidget:po'));
    }

    public function test_data_health_is_recomputed_on_the_queue_and_measured_on_the_recent_window(): void
    {
        Queue::fake();
        Livewire::test(DataHealthCenter::class)->callAction('refresh');
        Queue::assertPushed(ComputeDataHealthJob::class, fn ($j) => $j->tenantId === $this->tenant->id && $j->dataset === null);

        // Only sales older than the window: not "no data" — stale.
        SalesTransaction::factory()->create(['tenant_id' => $this->tenant->id, 'date' => now()->subDays(200)->toDateString()]);
        $data = app(DataHealthService::class)->computeDataset($this->tenant->id, DataHealthSnapshot::DATASET_SALES);
        $this->assertNotSame(DataHealthSnapshot::STATUS_NO_DATA, $data['status']);
        $this->assertSame(0, $data['records_received']);
        $this->assertSame('last 90 days', $data['metrics']['scope']);
    }

    public function test_each_store_lists_its_own_first_investigations_and_closed_work_is_not_at_risk(): void
    {
        $a = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'A']);
        $b = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'B']);
        Investigation::factory()->count(55)->create(['tenant_id' => $this->tenant->id, 'primary_store_id' => $a->id, 'status' => 'open', 'revenue_at_risk' => 10, 'opened_at' => now()]);
        Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'primary_store_id' => $b->id, 'status' => 'open', 'revenue_at_risk' => 100, 'opened_at' => now()->subYear()]);
        Investigation::factory()->create(['tenant_id' => $this->tenant->id, 'primary_store_id' => $b->id, 'status' => 'closed', 'revenue_at_risk' => 900, 'opened_at' => now()->subYear()]);

        $groups = collect((new InvestigationsByStore())->getStoreGroups())->keyBy('store');

        $this->assertSame(50, $groups['A']['shown']);
        $this->assertSame(2, $groups['B']['shown'], 'B is listed even though A has more recent work');
        $this->assertStringContainsString('100', $groups['B']['value_fmt'], 'the closed 900 is not at risk');
        $this->assertStringNotContainsString('1,000', $groups['B']['value_fmt']);
    }

    public function test_usage_counts_come_from_statistics_on_a_big_table(): void
    {
        $other = $this->createTenant();
        foreach ([[$this->tenant->id, 30], [$other->id, 10]] as [$t, $n]) {
            DB::table('sales_daily')->insert(array_map(fn ($i) => ['tenant_id' => $t, 'store_id' => null, 'sku' => "S{$i}", 'date' => '2026-09-01',
                'units_sold' => 1, 'revenue' => 1, 'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()], range(1, $n)));
        }
        DB::statement('ANALYZE sales_daily');

        $est = TableStats::rowsByTenant('sales_daily', exactBelow: 1);

        $this->assertSame(30, $est[$this->tenant->id]);
        $this->assertSame(10, $est[$other->id]);
    }
}
