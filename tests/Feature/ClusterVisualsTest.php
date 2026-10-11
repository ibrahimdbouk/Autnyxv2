<?php

namespace Tests\Feature;

use App\Filament\Pages\ClusteringMethod;
use App\Filament\Resources\StoreClusterResource;
use App\Models\Store;
use App\Models\StoreCluster;
use App\Models\StoreFeature;
use App\Models\Tenant;
use App\Platform\Intelligence\Clustering\ClusterService;
use App\Services\Clustering\ClusterVisuals;
use App\Support\Geo\Gazetteer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * v2.5 — the store clusters drawn: on a schematic map (coordinates, else the
 * store's city, drawn as approximate), stores per cluster, each cluster's
 * profile against the average store, and how structural groups regroup by
 * behaviour.
 */
class ClusterVisualsTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['currency' => 'AED']);
        $this->actingAsTenantAdmin($this->tenant);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);
    }

    /** Two kinds of hypermarket in Dubai and Sharjah: premium big baskets and value small ones. */
    private function estate(): void
    {
        $rows = [
            ['P1', 'Dubai', 25.20, 55.27, 500, 200], ['P2', 'Dubai', null, null, 520, 210], ['P3', 'Sharjah', null, null, 510, 205],
            ['V1', 'Sharjah', 25.34, 55.42, 80, 20], ['V2', 'Atlantis', null, null, 85, 22], ['V3', 'al-ain', null, null, 82, 21],
        ];
        foreach ($rows as [$code, $city, $lat, $lng, $basket, $price]) {
            $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => "Store {$code}", 'code' => $code, 'format' => 'Hypermarket', 'region' => 'North',
                'city' => $city, 'latitude' => $lat, 'longitude' => $lng]);
            StoreFeature::create(['tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'window_days' => 90, 'computed_at' => now(),
                'revenue' => 50000, 'active_skus' => 1000, 'basket_count' => 100, 'avg_daily_revenue' => $code[0] === 'P' ? 900 : 300,
                'avg_basket_value' => $basket, 'avg_selling_price' => $price, 'sku_productivity' => 250, 'promo_share' => 0.1, 'growth_ratio' => 1.0]);
        }
        $svc = app(ClusterService::class);
        $svc->setActiveMethod($this->tenant->id, 'demand');
        $svc->rebuild($this->tenant->id, 'demand');
    }

    public function test_the_gazetteer_finds_cities_however_they_are_written(): void
    {
        $this->assertSame([25.2048, 55.2708], Gazetteer::find('dubai'));
        $this->assertSame(Gazetteer::find('Al Ain'), Gazetteer::find('al-ain'));
        $this->assertSame(Gazetteer::find('Al Ain'), Gazetteer::find('El Ain'));
        $this->assertSame(Gazetteer::find('Kuwait'), Gazetteer::find('Kuwait City'));
        $this->assertSame('Ras Al Khaimah', Gazetteer::label('ras al khaimah'));
        $this->assertNull(Gazetteer::find('Atlantis'));
        $this->assertNull(Gazetteer::find(null));
    }

    public function test_every_store_is_placed_by_its_coordinates_else_its_city(): void
    {
        $this->estate();
        $v = app(ClusterVisuals::class)->overview($this->tenant->id, 'AED');

        $this->assertSame(['exact' => 2, 'city' => 3, 'none' => 1], $v['map']['placed'], 'the store in an unknown city is counted, not drawn');
        $this->assertCount(5, $v['map']['points']);
        $this->assertCount(3, array_filter($v['map']['points'], fn ($p) => $p['approx']));
        $this->assertEqualsCanonicalizing(['Dubai', 'Sharjah', 'Al Ain'], array_column($v['map']['landmarks'], 'label'));
        $exact = collect($v['map']['points'])->first(fn ($p) => ! $p['approx'] && str_contains($p['tip'], 'Store P1'));
        $this->assertEqualsWithDelta(25.20, $exact['lat'], 1e-6, 'exact coordinates are not moved');
        $approx = collect($v['map']['points'])->first(fn ($p) => str_contains($p['tip'], 'Store P2'));
        $this->assertEqualsWithDelta(25.2048, $approx['lat'], 0.05, 'a city-placed store sits at its city');
        $this->assertStringContainsString('placed at its city', $approx['tip']);
        $this->assertSame(6, $v['stores']);
        $this->assertSame(6, array_sum(array_column($v['donut'], 'value')));
        $this->assertCount(6, $v['scatter']);
    }

    public function test_each_cluster_is_profiled_against_the_average_store(): void
    {
        $this->estate();
        $v = app(ClusterVisuals::class)->overview($this->tenant->id, 'AED');
        $premium = StoreCluster::where('tenant_id', $this->tenant->id)->where('method', 'demand')->get()
            ->first(fn ($c) => $c->stores->pluck('code')->contains('P1'));
        $this->assertSame('Behavioural group · higher prices', $premium->label, 'two groups with the same profile tiers are told apart by what sets them apart');
        $cell = $v['profile']['cells'][(string) $premium->id]['avg_basket_value'];
        // Premium baskets average 510 against 296 for the average store.
        $this->assertEqualsWithDelta(510 / ((500 + 520 + 510 + 80 + 85 + 82) / 6), $cell['v'], 0.001);
        $this->assertGreaterThan(1.5, $cell['v']);
        $this->assertSame(array_keys(ClusterVisuals::FEATURES), array_column($v['profile']['cols'], 'key'));

        // On a cluster's own page the others are drawn faint.
        $focused = app(ClusterVisuals::class)->overview($this->tenant->id, 'AED', $premium->id);
        $points = collect($focused['map']['points']);
        $this->assertNotEmpty($points->where('dim', true), 'the other clusters are drawn faint');
        $this->assertTrue($points->filter(fn ($p) => str_contains($p['tip'], ' · ' . $premium->label))->every(fn ($p) => ! $p['dim']), 'its own stores in colour');
    }

    public function test_the_map_and_the_regrouping_are_drawn_without_inline_css(): void
    {
        $html = Blade::render('<x-ui.viz.map :points="$p" :landmarks="$l" :series="[1 => \'A\']" />', [
            'p' => [['lat' => 25.2, 'lng' => 55.3, 's' => 1, 'tip' => 'Store A'], ['lat' => 25.3, 'lng' => 55.4, 's' => 1, 'approx' => true, 'tip' => 'Store B']],
            'l' => [['lat' => 25.2048, 'lng' => 55.2708, 'label' => 'Dubai']],
        ]);
        $this->assertStringContainsString('<title>Store A</title>', $html);
        $this->assertStringContainsString('Dubai', $html);
        $this->assertStringContainsString('°N', $html);
        $this->assertStringContainsString('Placed at its city', $html);
        $html .= Blade::render('<x-ui.viz.sankey :left="$l" :right="$r" :links="$k" />', [
            'l' => [['key' => 'H', 'label' => 'Hypermarket']], 'r' => [['key' => 'A', 'label' => 'Premium', 's' => 1], ['key' => 'B', 'label' => 'Value', 's' => 2]],
            'k' => [['from' => 'H', 'to' => 'A', 'n' => 3], ['from' => 'H', 'to' => 'B', 'n' => 3]],
        ]);
        $this->assertStringContainsString('Hypermarket → Premium: 3 stores', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/', $html);
    }

    public function test_the_cluster_screens_draw_it(): void
    {
        $this->estate();
        $this->get(StoreClusterResource::getUrl('index', tenant: $this->tenant))->assertOk()
            ->assertSee('Where the clusters are')->assertSee('Stores per cluster')->assertSee('How each cluster trades against the average store')->assertSee('Basket value against daily sales');
        $cluster = StoreCluster::where('tenant_id', $this->tenant->id)->where('method', 'demand')->first();
        $this->get(StoreClusterResource::getUrl('detail', ['record' => $cluster], tenant: $this->tenant))->assertOk()
            ->assertSee('Where this cluster')->assertSee('Faint: the other clusters');
        $this->get(ClusteringMethod::getUrl(tenant: $this->tenant))->assertOk()
            ->assertSee('How the stores regroup')->assertSee('The behavioural groups on the map');
    }

    public function test_the_street_map_carries_the_stores_and_street_view_only_where_they_have_coordinates(): void
    {
        config(['autnyx.maps.enabled' => true]);
        $points = [['lat' => 25.2, 'lng' => 55.3, 's' => 2, 'tip' => 'Store A'], ['lat' => 25.3, 'lng' => 55.4, 's' => 1, 'approx' => true, 'tip' => 'Store B'],
            ['lat' => 25.1, 'lng' => 55.2, 's' => 1, 'dim' => true, 'tip' => 'Store C']];
        $html = Blade::render('<x-ui.viz.map :points="$p" />', ['p' => $points]);
        $this->assertStringContainsString('Street map', $html);
        $this->assertStringContainsString('Schematic', $html);
        $this->assertSame(1, preg_match('/data-ax-map="([^"]+)"/', $html, $m));
        $cfg = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
        $this->assertSame('https://tile.openstreetmap.org/{z}/{x}/{y}.png', $cfg['tiles']);
        $this->assertCount(3, $cfg['points']);
        [$a, $b, $c] = $cfg['points'];
        $this->assertSame('ax-viz-s2', $a['cls']);
        $this->assertSame('https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=25.2,55.3', $a['links'][0]['url']);
        $this->assertNull($b['links'], 'a store placed at its city gets no Street View: it is not its place');
        $this->assertTrue($b['approx']);
        $this->assertSame('ax-viz-base', $c['cls']);
        $this->assertStringContainsString('<title>Store A</title>', $html, 'the schematic stays one click away');
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/', $html);

        config(['autnyx.maps.enabled' => false]);
        $off = Blade::render('<x-ui.viz.map :points="$p" />', ['p' => $points]);
        $this->assertStringNotContainsString('data-ax-map', $off);
        $this->assertStringNotContainsString('Street map', $off);
    }

    public function test_the_tile_host_is_let_through_the_csp_and_nothing_wider(): void
    {
        config(['autnyx.maps.enabled' => true, 'autnyx.maps.tiles' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png']);
        $this->assertSame(['https://tile.openstreetmap.org'], \App\Support\Security\Csp::mapOrigins());
        $this->assertStringContainsString('img-src \'self\' data: blob: https://tile.openstreetmap.org', \App\Support\Security\Csp::policy(null));
        config(['autnyx.maps.tiles' => 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png']);
        $this->assertSame(['https://*.basemaps.cartocdn.com'], \App\Support\Security\Csp::mapOrigins());
        config(['autnyx.maps.tiles' => 'http://tiles.example.com/{z}/{x}/{y}.png']);
        $this->assertSame([], \App\Support\Security\Csp::mapOrigins(), 'never plain http');
        config(['autnyx.maps.enabled' => false, 'autnyx.maps.tiles' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png']);
        $this->assertSame([], \App\Support\Security\Csp::mapOrigins());
    }
}
