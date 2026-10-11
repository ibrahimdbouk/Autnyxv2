<?php

namespace Tests\Feature;

use App\Filament\Dashboards\RootCauseDashboard;
use App\Filament\Pages\FinancialBreakdown;
use App\Filament\Pages\InvestigationsByStore;
use App\Filament\Pages\SupplierScorecard;
use App\Models\Tenant;
use App\Services\Metrics\RiskMap;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * v2.5 — the Root Cause visual layer: where the money at risk sits (store ×
 * department, store × kind of problem, the stores most at risk), what makes up
 * a financial figure, and the suppliers on one picture. Open work only.
 */
class RootCauseVisualsTest extends TestCase
{
    private Tenant $tenant;

    /** @var array<int,int> */
    private array $stores = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['currency' => 'AED']);
        $this->actingAsTenantAdmin($this->tenant);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);
        $t = $this->tenant->id;
        foreach ([1, 2] as $n) {
            $this->stores[$n] = DB::table('stores')->insertGetId(['tenant_id' => $t, 'name' => "Store {$n}", 'code' => "S{$n}", 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['F1' => 'Fresh', 'B1' => 'Bakery'] as $sku => $dept) {
            DB::table('products')->insert(['tenant_id' => $t, 'sku' => $sku, 'name' => $dept . ' item', 'category' => $dept, 'department' => $dept,
                'unit_cost' => 5, 'selling_price' => 9, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function investigation(int $store, string $sku, float $value, string $status = 'open', string $priority = 'medium', array $rules = []): int
    {
        $id = DB::table('investigations')->insertGetId(['tenant_id' => $this->tenant->id, 'title' => "On {$sku}", 'status' => $status, 'priority' => $priority,
            'primary_sku' => $sku, 'primary_store_id' => $this->stores[$store], 'revenue_at_risk' => $value, 'opened_at' => now()->subDays(2),
            'created_at' => now(), 'updated_at' => now()]);
        foreach ($rules as $rule => $dismissed) {
            DB::table('anomalies')->insert(['tenant_id' => $this->tenant->id, 'rule_type' => $rule, 'severity' => 'medium', 'sku' => $sku, 'store_id' => $this->stores[$store],
                'description' => 'x', 'detected_at' => now(), 'investigation_id' => $id, 'dismissed_at' => $dismissed ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    private function estate(): void
    {
        $this->investigation(1, 'F1', 6000, 'open', 'critical', ['stockout' => false, 'shrinkage' => false]);
        $this->investigation(1, 'F1', 2000, 'in_progress', 'high', ['stockout' => false]);
        $this->investigation(1, 'B1', 1000, 'open', 'low', ['sales_drop' => true]);       // the finding is dismissed
        $this->investigation(2, 'B1', 3000, 'open', 'medium', ['sales_drop' => false]);
        $this->investigation(2, 'F1', 50000, 'resolved', 'critical', ['stockout' => false]); // closed: no longer at risk
    }

    public function test_the_money_at_risk_is_mapped_by_store_and_department_open_work_only(): void
    {
        $this->estate();
        $m = app(RiskMap::class)->byDepartment($this->tenant->id, null, 'AED');

        $this->assertSame([$this->stores[1], $this->stores[2]], array_column($m['rows'], 'key'), 'most at risk first');
        $this->assertSame(['Fresh', 'Bakery'], array_column($m['cols'], 'key'));
        $this->assertSame(1.0, $m['cells'][$this->stores[1]]['Fresh']['v'], 'the biggest cell is the full shade');
        $this->assertStringContainsString('across 2 open investigations', $m['cells'][$this->stores[1]]['Fresh']['tip']);
        $this->assertEqualsWithDelta(3000 / 8000, $m['cells'][$this->stores[2]]['Bakery']['v'], 1e-9);
        $this->assertArrayNotHasKey('Fresh', $m['cells'][$this->stores[2]], 'a resolved investigation puts nothing at risk');
        $this->assertEqualsWithDelta(12000.0, $m['total'], 0.01);
        $this->assertNull(app(RiskMap::class)->byDepartment($this->createTenant()->id, null, 'AED'));
    }

    public function test_problems_are_counted_per_store_and_the_stores_ranked(): void
    {
        $this->estate();
        $p = app(RiskMap::class)->byProblem($this->tenant->id, null);
        $this->assertSame('2', $p['cells'][$this->stores[1]]['stockout']['text']);
        $this->assertSame('1', $p['cells'][$this->stores[2]]['sales_drop']['text']);
        $this->assertArrayNotHasKey('sales_drop', $p['cells'][$this->stores[1]], 'dismissed findings are left out');
        $this->assertArrayNotHasKey('stockout', $p['cells'][$this->stores[2]], 'findings on closed investigations are left out');

        $top = app(RiskMap::class)->topStores($this->tenant->id, null, 'AED');
        $this->assertSame(['Store 1', 'Store 2'], array_column($top, 'label'));
        $this->assertSame([9000.0, 3000.0], array_column($top, 'value'));
        $this->assertSame('ax-viz-down', $top[0]['class'], 'two of its three open investigations are high or critical');
        $this->assertSame('ax-viz-s1', $top[1]['class']);
    }

    public function test_the_screens_draw_it(): void
    {
        $this->estate();
        $html = view('filament.dashboards.root-cause', (new RootCauseDashboard)->data($this->tenant))->render();
        $this->assertStringContainsString('Where the money at risk sits', $html);
        $this->assertDoesNotMatchRegularExpression('/<svg class="ax-viz[^"]*"[^>]*style=/', $html);

        $this->get(InvestigationsByStore::getUrl(tenant: $this->tenant))->assertOk()
            ->assertSee('Most at risk')->assertSee('What kind of problem, where');
        $this->get(FinancialBreakdown::getUrl(['metric' => 'revenue_at_risk'], tenant: $this->tenant))->assertOk()
            ->assertSee('What makes up the figure');
    }

    public function test_suppliers_are_drawn_by_fill_rate_and_on_time_delivery(): void
    {
        $t = $this->tenant->id;
        $rows = [];
        foreach (['Good Foods' => [100, 3], 'Late Co' => [60, 9]] as $name => [$received, $late]) {
            $sid = DB::table('suppliers')->insertGetId(['tenant_id' => $t, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            for ($i = 0; $i < 10; $i++) {
                $order = now()->subDays(10 + 5 * $i);
                $rows[] = ['tenant_id' => $t, 'supplier_id' => $sid, 'supplier' => $name, 'po_number' => $name[0] . $i, 'sku' => 'F1', 'qty_ordered' => 100,
                    'qty_received' => $received, 'unit_cost' => 10, 'order_date' => $order->toDateString(), 'expected_date' => $order->copy()->addDays(3)->toDateString(),
                    'received_date' => $order->copy()->addDays($i % 2 ? $late : 3)->toDateString(), 'created_at' => now(), 'updated_at' => now()];
            }
        }
        DB::table('purchase_orders')->insert($rows);

        $this->get(SupplierScorecard::getUrl(tenant: $this->tenant))->assertOk()
            ->assertSee('Fill rate against on-time delivery')->assertSee('Late Co · grade D', false);
    }
}
