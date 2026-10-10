<?php

namespace Tests\Feature;

use App\Filament\Pages\AssortmentCleanUp;
use App\Filament\Pages\AssortmentDeals;
use App\Filament\Pages\AssortmentGroups;
use App\Filament\Pages\AssortmentNewRangePage;
use App\Filament\Pages\AssortmentNewStores;
use App\Filament\Widgets\Assortment\CleanUpProgrammes;
use App\Models\AssortmentConstraint;
use App\Models\AssortmentGap;
use App\Models\AssortmentGrouping;
use App\Models\AssortmentNewRange;
use App\Models\AssortmentPlan;
use App\Models\AssortmentProgramme;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Intelligence\Clustering\Strategies\RangeMixClustering;
use App\Services\Assortment\AssortmentEngine;
use App\Services\Assortment\AssortmentUploads;
use App\Services\Assortment\CommercialConstraints;
use App\Services\Assortment\DecisionService;
use App\Services\Assortment\NewStoreRanges;
use App\Services\Assortment\OutcomeMeasurer;
use App\Services\Assortment\PeerGroups;
use App\Services\Assortment\PlanService;
use App\Services\Assortment\SeasonalIndex;
use App\Services\Assortment\StoreGroupings;
use App\Services\Assortment\TailProgrammes;
use App\Services\Assortment\ValidationGate;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Concerns\SeedsAssortmentEstate;
use Tests\TestCase;

/**
 * v2.2 sharper demand (seasons, commercial deals), v2.3 new stores and tail
 * clean-up programmes, v2.4 demand-based store groups.
 */
class AssortmentV22To24Test extends TestCase
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
    }

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'v22') . '.csv';
        file_put_contents($path, $body);

        return $path;
    }

    private function goLive(): void
    {
        $gate = app(ValidationGate::class);
        foreach (array_keys(AssortmentGap::TYPES) as $type) {
            foreach ($gate->sample($this->tenant, $type) as $g) {
                $gate->review($g, $this->admin, AssortmentGap::VERDICT_SENSIBLE);
            }
        }
        $this->assertTrue($gate->goLive($this->tenant->fresh(), $this->admin));
        $this->tenant = $this->tenant->fresh();
    }

    private function gap(int $store, string $sku, string $type): ?AssortmentGap
    {
        return AssortmentGap::where('tenant_id', $this->tenant->id)->where('store_id', $this->stores[$store])->where('sku', $sku)->where('type', $type)->first();
    }

    // ── v2.2 Seasons ──────────────────────────────────────────────────────────

    public function test_a_year_of_sales_gives_each_category_its_season(): void
    {
        $this->seedEstate(days: 400);
        $windowFrom = Carbon::parse($this->asOf)->subDays(89)->toDateString();
        DB::table('sales_daily')->where('tenant_id', $this->tenant->id)->where('date', '>=', $windowFrom)
            ->update(['units_sold' => DB::raw('units_sold * 2'), 'revenue' => DB::raw('revenue * 2')]);

        $s = app(SeasonalIndex::class)->forTenant($this->tenant->id, $this->asOf, 90);
        $this->assertTrue($s['Dairy']['applied']);
        $this->assertEqualsWithDelta(2 / ((275 + 180) / 365), $s['Dairy']['index'], 0.03, 'the window sells twice a normal day');
        $this->assertEqualsWithDelta(1 / $s['Dairy']['index'], SeasonalIndex::factor($s, 'Dairy'), 1e-9);
        $this->assertSame(1.0, SeasonalIndex::factor($s, 'Bakery'));
        $this->assertCount(12, $s['Dairy']['months']);

        $run = app(AssortmentEngine::class)->run($this->tenant);
        $this->assertTrue($run->stats['seasons']['Dairy']['applied']);
        $add = $this->gap(1, 'ADD-ME', AssortmentGap::TYPE_ADD);
        $this->assertEqualsWithDelta($s['Dairy']['index'], $add->evidence['season_index'], 1e-6);
        $this->assertStringContainsString('Seasonal: Dairy sells', implode(' ', $add->explanation['evidence'] ?? []));

        // Under a year: no curve, nothing adjusted.
        $this->assertSame([], app(SeasonalIndex::class)->forTenant($this->tenant->id, Carbon::parse($this->asOf)->subDays(100)->toDateString(), 90));
    }

    // ── v2.2 Commercial deals ─────────────────────────────────────────────────

    public function test_a_deal_protects_by_line_and_by_floor(): void
    {
        $products = [
            'A' => ['category' => 'Dairy', 'brand' => 'Farm', 'supplier' => 'Acme'], 'B' => ['category' => 'Dairy', 'brand' => 'Farm', 'supplier' => 'Acme'],
            'C' => ['category' => 'Dairy', 'brand' => 'Farm', 'supplier' => 'Zed'], 'D' => ['category' => 'Dairy', 'brand' => 'Other', 'supplier' => 'Zed'],
        ];
        $shelf = ['A', 'B', 'C', 'D'];
        $keep = [['id' => 1, 'kind' => 'keep', 'sku' => 'D', 'until' => '2027-01-31', 'note' => 'Contract 42', 'brand' => null, 'supplier' => null, 'category' => null, 'min' => 0]];
        $this->assertStringContainsString('until 31 Jan 2027 (Contract 42)', CommercialConstraints::keeps($keep, 'D', $shelf, $products)['why']);
        $this->assertNull(CommercialConstraints::keeps($keep, 'A', $shelf, $products));

        $floor = [['id' => 2, 'kind' => 'brand_min', 'brand' => 'farm', 'brand_label' => 'Farm', 'supplier' => null, 'category' => 'Dairy', 'min' => 3, 'until' => null, 'sku' => null]];
        $this->assertNotNull(CommercialConstraints::keeps($floor, 'A', $shelf, $products), '3 Farm on the shelf, at least 3: each stays');
        $floor[0]['min'] = 2;
        $this->assertNull(CommercialConstraints::keeps($floor, 'A', $shelf, $products), 'without sales, above the floor a delist may go');
        $sales = ['A' => 100, 'B' => 50, 'C' => 10];
        $this->assertNotNull(CommercialConstraints::keeps($floor, 'A', $shelf, $products, $sales));
        $this->assertNull(CommercialConstraints::keeps($floor, 'C', $shelf, $products, $sales), 'the 2 that sell best stay; the third may go');

        $sup = [['id' => 3, 'kind' => 'supplier_min', 'supplier' => 'zed', 'supplier_label' => 'Zed', 'brand' => null, 'category' => null, 'min' => 2, 'until' => null, 'sku' => null]];
        $this->assertStringContainsString('from Zed', CommercialConstraints::keeps($sup, 'C', $shelf, $products)['why']);
    }

    public function test_deals_hold_delists_back_and_say_what_they_cost(): void
    {
        $this->seedEstate();
        AssortmentConstraint::create(['tenant_id' => $this->tenant->id, 'kind' => 'keep', 'sku' => 'DUD', 'store_id' => $this->stores[1], 'note' => 'Listing fee']);
        AssortmentConstraint::create(['tenant_id' => $this->tenant->id, 'kind' => 'keep', 'sku' => 'SHIELD', 'until' => Carbon::parse($this->asOf)->subDay()->toDateString()]);
        $run = app(AssortmentEngine::class)->run($this->tenant);

        $this->assertNull($this->gap(1, 'DUD', AssortmentGap::TYPE_DELIST), 'a deal is a hard limit');
        $this->assertNotNull($this->gap(1, 'SHIELD', AssortmentGap::TYPE_DELIST), 'an expired deal no longer binds');
        $this->assertSame(1, $run->stats['decisions']['kept_by_deal']);
        $deal = AssortmentConstraint::where('sku', 'DUD')->first();
        $this->assertSame(1, $deal->cost['decisions']);
        $this->assertGreaterThan(0, $deal->cost['high']);

        $plan = AssortmentPlan::where('tenant_id', $this->tenant->id)->where('store_id', $this->stores[1])->first();
        $this->assertStringStartsWith('Deal: keep this line', collect($plan->changes)->firstWhere('sku', 'DUD')['why'] ?? '');
    }

    public function test_deals_are_loaded_from_a_file_and_kept_on_a_native_table(): void
    {
        $this->seedEstate();
        $r = app(AssortmentUploads::class)->importConstraints($this->tenant->id, $this->csv(
            "kind,sku,brand,supplier,category,store,min,until,note\n"
            . "keep,DUD,,,,S1,,2027-03-31,Fee\n"
            . "brand floor,,Farm,,Dairy,,3,,Own label\n"
            . "supplier,,,Acme,,,2,,\n"
            . "keep,NOPE,,,,,,,\n"
            . "brand,,,,,,0,,\n"
            . "promo,DUD,,,,,,,\n"));
        $this->assertSame(3, $r['added']);
        $this->assertCount(3, $r['skipped']);
        $this->assertSame('2027-03-31', AssortmentConstraint::where('kind', 'keep')->first()->until->toDateString());
        $this->assertSame($this->stores[1], AssortmentConstraint::where('kind', 'keep')->first()->store_id);

        $deal = AssortmentConstraint::where('kind', 'brand_min')->first();
        Livewire::test(AssortmentDeals::class)
            ->assertCanSeeTableRecords(AssortmentConstraint::all())
            ->assertSee('At least 3 Farm in Dairy')->assertSee('Worked out at the next run')
            ->callAction('add', ['kind' => 'keep', 'sku' => 'BASE-1', 'store_id' => null, 'format' => null, 'until' => null, 'note' => 'New contract'])
            ->assertHasNoActionErrors()
            ->callTableAction('delete', $deal);
        $this->assertSame(3, AssortmentConstraint::count());

        $user = $this->createUser($this->tenant);
        $this->actingAs($user);
        $this->get(AssortmentDeals::getUrl(tenant: $this->tenant))->assertForbidden();
    }

    // ── v2.3 New stores and remodels ──────────────────────────────────────────

    public function test_a_new_store_range_comes_from_similar_stores_and_fits_its_metres(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $svc = app(NewStoreRanges::class);

        $d = $svc->donors($this->tenant->id, 'Supermarket', 'North');
        $this->assertSame('format_region', $d['basis']);
        $this->assertCount(6, $d['stores']);
        try {
            $svc->build($this->tenant, $this->admin, ['kind' => 'new', 'format' => 'Hypermarket']);
            $this->fail('no similar stores, no range');
        } catch (HttpException $e) {
            $this->assertStringContainsString('at least 3 similar stores', $e->getMessage());
        }

        $r = $svc->build($this->tenant, $this->admin, ['kind' => 'new', 'format' => 'Supermarket', 'region' => 'North']);
        $dairy = $r->result['Dairy'];
        $this->assertSame($dairy['target'], $dairy['count'], 'as many as the similar stores typically carry');
        $this->assertSame('core', $dairy['products'][0]['band']);
        $this->assertSame(array_values(array_unique(array_column($dairy['products'], 'sku'))), array_column($dairy['products'], 'sku'));
        $this->assertGreaterThan(0, $dairy['sales'][1]);
        $this->assertLessThanOrEqual($dairy['sales'][2], $dairy['sales'][1]);
        $this->assertFalse($svc->ownHistory($r));

        // With widths and metres: fitted to the shelf.
        $lines = "sku,width,facings\n";
        foreach (['ADD-ME', 'OUTAGE', 'DUD', 'SHIELD', 'BASE-1', 'BASE-2', 'BASE-3', 'BASE-4', 'BASE-5', 'BASE-6', 'BASE-7', 'BASE-8'] as $sku) {
            $lines .= "{$sku},10,2\n";
        }
        app(AssortmentUploads::class)->importProductSpace($this->tenant->id, $this->csv($lines));
        app(AssortmentUploads::class)->importShelfSpace($this->tenant->id, $this->csv("format,category,metres\nSupermarket,Dairy,9\n"));
        $m = $svc->build($this->tenant, $this->admin, ['kind' => 'new', 'format' => 'Supermarket', 'metres' => ['Dairy' => '1.0']]);
        $this->assertSame(5, $m->result['Dairy']['count'], '0.2 m each into 1 m');
        $this->assertEquals(1.0, $m->result['Dairy']['metres_used']);
        $this->assertSame('asked', $m->result['Dairy']['metres_from']);

        $this->get(AssortmentNewRangePage::getUrl(['range' => $m->id], tenant: $this->tenant))->assertOk()
            ->assertSee('Built from')->assertSee('Greek yogurt')->assertSee('In the range');
        Livewire::test(AssortmentNewRangePage::class, ['range' => $m->id])->callAction('csv')->assertFileDownloaded('range-' . $m->id . '.csv');
    }

    public function test_a_remodel_keeps_the_store_s_range_and_adds_what_similar_stores_expect(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $r = app(NewStoreRanges::class)->build($this->tenant, $this->admin, ['kind' => 'remodel', 'store_id' => $this->stores[1]]);
        $states = collect($r->result['Dairy']['products'])->pluck('state', 'sku');
        $this->assertSame('add', $states['ADD-ME'], 'every similar store carries it, this one does not');
        $this->assertSame('keep', $states['BASE-1']);
        $this->assertSame([], $r->result['Dairy']['remove'], 'no new metres: nothing removed for space');
        $this->assertCount(5, $r->donors['stores'], 'its own sales are not its benchmark');

        // A deal binds a remodel too: kept even when the shelf is short.
        AssortmentConstraint::create(['tenant_id' => $this->tenant->id, 'kind' => 'keep', 'sku' => 'DUD', 'store_id' => $this->stores[1]]);
        $lines = "sku,width,facings\n";
        foreach (['ADD-ME', 'OUTAGE', 'DUD', 'SHIELD', 'BASE-1', 'BASE-2', 'BASE-3', 'BASE-4', 'BASE-5', 'BASE-6', 'BASE-7', 'BASE-8'] as $sku) {
            $lines .= "{$sku},10,2\n";
        }
        app(AssortmentUploads::class)->importProductSpace($this->tenant->id, $this->csv($lines));
        $short = app(NewStoreRanges::class)->build($this->tenant, $this->admin, ['kind' => 'remodel', 'store_id' => $this->stores[1], 'metres' => ['Dairy' => '0.4']]);
        $first = $short->result['Dairy']['products'][0];
        $this->assertSame('DUD', $first['sku']);
        $this->assertStringStartsWith('Deal: keep this line', $first['deal']);
        $this->assertSame(1, $short->result['Dairy']['deals']);
        $this->assertNotEmpty($short->result['Dairy']['remove']);

        Livewire::test(AssortmentNewStores::class)
            ->assertCanSeeTableRecords(AssortmentNewRange::all())
            ->callAction('build', ['kind' => 'new', 'store_id' => null, 'format' => 'Supermarket', 'region' => null, 'categories' => ['Dairy'], 'metres' => [], 'name' => 'Downtown opening'])
            ->assertHasNoActionErrors();
        $this->assertSame(['Dairy'], array_keys(AssortmentNewRange::where('name', 'Downtown opening')->first()->result));
    }

    // ── v2.3 Tail clean-up programmes ─────────────────────────────────────────

    public function test_a_tail_programme_accepts_the_delists_everywhere_and_follows_them(): void
    {
        $this->seedEstate();
        app(AssortmentEngine::class)->run($this->tenant);
        $this->goLive();
        $dud = $this->gap(1, 'DUD', AssortmentGap::TYPE_DELIST);
        foreach ([2, 3, 4] as $n) {
            $dud->replicate()->forceFill(['store_id' => $this->stores[$n], 'status' => AssortmentGap::STATUS_OPEN])->save();
        }
        $svc = app(TailProgrammes::class);
        $c = $svc->candidates($this->tenant);
        $this->assertArrayHasKey('DUD', $c);
        $this->assertSame(4, $c['DUD']['stores']);
        $this->assertSame(6, $c['DUD']['carrying']);
        $this->assertArrayNotHasKey('SHIELD', $c, 'one store is not a programme');

        Livewire::test(AssortmentCleanUp::class)
            ->assertSee('Goat kefir')->assertSee('4 of 6 carrying (67%)')
            ->callTableBulkAction('programme', ['DUD'], ['name' => 'Kefir exit'])
            ->assertHasNoTableBulkActionErrors();
        $p = AssortmentProgramme::firstOrFail();
        $this->assertSame(['DUD'], $p->skus);
        $this->assertSame(4, $p->stores);
        $this->assertArrayNotHasKey('DUD', $svc->candidates($this->tenant), 'in a programme, not offered again');

        Livewire::test(CleanUpProgrammes::class)->callTableAction('accept', $p, ['assignee_id' => null, 'due_at' => now()->addDays(10)->toDateString()])
            ->assertHasNoTableActionErrors();
        $p->refresh();
        $this->assertSame(AssortmentProgramme::ACCEPTED, $p->status);
        $this->assertCount(4, $p->gap_ids);
        $this->assertSame(4, AssortmentGap::whereIn('id', $p->gap_ids)->where('status', AssortmentGap::STATUS_ACCEPTED)->count());
        $this->assertSame(['total' => 4, 'done' => 0, 'to_do' => 4, 'cancelled' => 0, 'measured' => 0, 'worked' => 0, 'uplift' => 0.0], $svc->progress($p));

        foreach (AssortmentGap::whereIn('id', $p->gap_ids)->get() as $g) {
            app(DecisionService::class)->markDone($g, $this->admin, 'done', now()->toDateString());
        }
        $this->assertSame(4, $svc->progress($p->fresh())['done']);
        $this->assertSame(AssortmentProgramme::DONE, $p->fresh()->status);
    }

    // ── v2.4 Demand-based store groups ────────────────────────────────────────

    /** Ten stores of one format: five sell mostly Dairy, five mostly Bakery. */
    private function twoKinds(): void
    {
        $t = $this->tenant->id;
        foreach (['D1' => 'Dairy', 'B1' => 'Bakery'] as $sku => $cat) {
            DB::table('products')->insert(['tenant_id' => $t, 'sku' => $sku, 'name' => $cat . ' item', 'category' => $cat, 'unit_cost' => 6, 'selling_price' => 10,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $this->stores[$i] = DB::table('stores')->insertGetId(['tenant_id' => $t, 'name' => "Store {$i}", 'code' => "S{$i}", 'format' => 'Supermarket',
                'region' => 'North', 'created_at' => now(), 'updated_at' => now()]);
            [$d, $b] = $i <= 5 ? [8 + $i % 2, 2] : [2, 8 + $i % 2];
            for ($day = 0; $day < 60; $day++) {
                $date = Carbon::parse($this->asOf)->subDays($day)->toDateString();
                foreach (['D1' => $d, 'B1' => $b] as $sku => $u) {
                    $rows[] = ['tenant_id' => $t, 'store_id' => $this->stores[$i], 'sku' => $sku, 'date' => $date, 'units_sold' => $u, 'revenue' => $u * 10,
                        'transaction_count' => 1, 'created_at' => now(), 'updated_at' => now()];
                }
            }
        }
        foreach (array_chunk($rows, 1000) as $c) {
            DB::table('sales_daily')->insert($c);
        }
    }

    public function test_stores_are_grouped_by_what_they_sell(): void
    {
        $this->twoKinds();
        $groups = app(RangeMixClustering::class)->clusterWith($this->tenant->id, [], $this->asOf);
        $this->assertCount(2, $groups);
        $labels = collect($groups)->pluck('label')->sort()->values()->all();
        $this->assertSame(['Bakery-led', 'Dairy-led'], $labels);
        $dairy = collect($groups)->firstWhere('label', 'Dairy-led');
        $this->assertSame(array_slice($this->stores, 0, 5), $dairy['store_ids']);
        $this->assertSame('Dairy', $dairy['params']['leads'][0]['category']);
    }

    public function test_store_groups_are_proposed_approved_versioned_and_retired(): void
    {
        $this->twoKinds();
        $svc = app(StoreGroupings::class);
        $this->assertSame(['format:supermarket'], array_keys(app(PeerGroups::class)->build($this->tenant->id)['groups']));

        $g = $svc->propose($this->tenant, $this->admin);
        $this->assertSame(1, $g->version);
        $this->assertSame(10, $g->comparison['moved'], 'from one group of ten to two of five: every store has new peers');
        $this->assertSame([10], $g->comparison['sizes_now']);
        $this->assertSame([5, 5], $g->comparison['sizes_proposed']);
        $this->assertSame(0, $g->comparison['unjudged_proposed']);
        $this->assertSame(['format:supermarket'], array_keys(app(PeerGroups::class)->build($this->tenant->id)['groups']), 'nothing changes before approval');

        $svc->approve($g, $this->admin);
        $peers = app(PeerGroups::class)->build($this->tenant->id);
        $this->assertSame(['range:v1:1', 'range:v1:2'], array_keys($peers['groups']));
        $key = $peers['assignment'][$this->stores[1]];
        $this->assertSame('range', $peers['groups'][$key]['basis']);
        $this->assertSame(array_slice($this->stores, 0, 5), StoreGroupings::members($this->tenant->id, $key));

        $again = $svc->propose($this->tenant, $this->admin);
        $this->assertSame(2, $again->version);
        $this->assertSame(0, $again->comparison['moved'], 'stable: the same stores, the same groups');
        $svc->reject($again, $this->admin, 'No change');

        $svc->retire($this->tenant, $this->admin);
        $this->assertSame(['format:supermarket'], array_keys(app(PeerGroups::class)->build($this->tenant->id)['groups']));
        $this->assertSame(AssortmentGrouping::RETIRED, $g->fresh()->status);
    }

    public function test_decisions_are_judged_and_measured_in_the_approved_groups(): void
    {
        $this->seedEstate();
        $svc = app(StoreGroupings::class);
        // One approved grouping holding all six estate stores, as version 1.
        $g = AssortmentGrouping::create(['tenant_id' => $this->tenant->id, 'version' => 1, 'status' => AssortmentGrouping::PROPOSED, 'method' => 'range',
            'groups' => ['range:v1:1' => ['key' => 'range:v1:1', 'label' => 'Dairy-led', 'members' => array_values($this->stores), 'profile' => []]],
            'assignment' => array_fill_keys(array_values($this->stores), 'range:v1:1'), 'comparison' => ['stores' => []]]);
        $svc->approve($g, $this->admin);
        $run = app(AssortmentEngine::class)->run($this->tenant);

        $add = $this->gap(1, 'ADD-ME', AssortmentGap::TYPE_ADD);
        $this->assertSame('range:v1:1', $add->peer_group);
        $this->assertSame(1, $run->stats['grouping']);
        $this->assertSame('Dairy-led', $add->evidence['peer_group']);
        $m = app(OutcomeMeasurer::class)->measure($add->forceFill(['done_at' => Carbon::parse($this->asOf)->subDays(60)]), 56);
        $this->assertSame(5, $m['control_stores'], 'controls come from the version it was judged in');

        Livewire::test(AssortmentGroups::class)->assertSee('groups-1')->assertSee('Dairy-led')
            ->callAction('propose')->assertNotified()
            ->assertSee('Proposal groups-2');
        $this->assertSame(AssortmentGrouping::PROPOSED, AssortmentGrouping::where('version', 2)->value('status'));

        $user = $this->createUser($this->tenant);
        $this->actingAs($user);
        $this->get(AssortmentGroups::getUrl(tenant: $this->tenant))->assertForbidden();
    }
}
