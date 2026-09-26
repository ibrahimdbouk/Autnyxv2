<?php

namespace Tests\Feature\Concerns;

use App\Models\Tenant;
use App\Platform\Intelligence\Clustering\ClusterService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A small, fully known estate for the Assortment tests: supermarkets in one
 * region (one peer group), daily sales and stock. Store 1 is judged on the
 * planted products:
 *
 *   ADD-ME   carried and sold by stores 2–6, never at store 1        → add
 *   OUTAGE   sold everywhere; store 1 in stock only 40% of days       → stockout-hidden, never delist
 *   DUD      store 1 sells 1/40th of peers, always in stock           → delist
 *   SHIELD   like DUD (must-stock in some tests)                       → delist unless protected
 *   BASE-n   ordinary sellers everywhere                               → nothing
 */
trait SeedsAssortmentEstate
{
    protected Tenant $tenant;

    /** @var array<int,int> store number => id */
    protected array $stores = [];

    protected string $asOf = '2026-09-20';

    protected function seedEstate(int $storeCount = 6, int $days = 200): void
    {
        $t = $this->tenant->id;
        for ($i = 1; $i <= $storeCount; $i++) {
            $this->stores[$i] = DB::table('stores')->insertGetId([
                'tenant_id' => $t, 'name' => "Store {$i}", 'code' => "S{$i}", 'format' => 'Supermarket', 'region' => 'North',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $skus = ['ADD-ME' => 'Greek yogurt', 'OUTAGE' => 'Oat milk', 'DUD' => 'Goat kefir', 'SHIELD' => 'Lactose-free cream'];
        for ($n = 1; $n <= 8; $n++) {
            $skus["BASE-{$n}"] = "Dairy item {$n}";
        }
        foreach ($skus as $sku => $name) {
            DB::table('products')->insert([
                'tenant_id' => $t, 'sku' => $sku, 'name' => $name, 'category' => 'Dairy', 'subcategory' => 'Chilled',
                'unit_cost' => 6, 'selling_price' => 10, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $sales = $stock = [];
        $end = Carbon::parse($this->asOf);
        for ($d = 0; $d < $days; $d++) {
            $date = $end->copy()->subDays($d)->toDateString();
            foreach ($this->stores as $num => $storeId) {
                foreach (array_keys($skus) as $sku) {
                    [$units, $onHand] = $this->plan($num, $sku, $d);
                    if ($units === null) {
                        continue;   // not carried at this store
                    }
                    $stock[] = ['tenant_id' => $t, 'store_id' => $storeId, 'sku' => $sku, 'on_hand_qty' => $onHand,
                        'as_of_date' => $date, 'created_at' => now(), 'updated_at' => now()];
                    if ($units > 0) {
                        $sales[] = ['tenant_id' => $t, 'store_id' => $storeId, 'sku' => $sku, 'date' => $date,
                            'units_sold' => $units, 'revenue' => $units * 10, 'transaction_count' => 1,
                            'created_at' => now(), 'updated_at' => now()];
                    }
                }
            }
        }
        foreach (array_chunk($sales, 1000) as $c) {
            DB::table('sales_daily')->insert($c);
        }
        foreach (array_chunk($stock, 1000) as $c) {
            DB::table('inventory_levels')->insert($c);
        }
        foreach ($this->stores as $storeId) {
            foreach (array_keys($skus) as $sku) {
                DB::table('inventory_current')->insert(['tenant_id' => $t, 'store_id' => $storeId, 'sku' => $sku,
                    'on_hand_qty' => 50, 'unit_cost' => 6, 'as_of_date' => $this->asOf, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        app(ClusterService::class)->rebuild($t, 'attribute');
    }

    /** [units sold that day, stock on hand] — units null = not carried. */
    protected function plan(int $store, string $sku, int $day): array
    {
        return match (true) {
            $sku === 'ADD-ME' && $store === 1              => [null, 0],
            $sku === 'ADD-ME'                              => [4, 30],
            $sku === 'OUTAGE' && $store === 1              => $day % 5 < 2 ? [4, 20] : [0, 0],   // in stock 40% of days
            $sku === 'OUTAGE'                              => [4, 20],
            in_array($sku, ['DUD', 'SHIELD'], true) && $store === 1 => [$day % 20 === 0 ? 2 : 0, 40],   // 0.1/day
            in_array($sku, ['DUD', 'SHIELD'], true)        => [4, 40],
            default                                        => [2 + ((int) substr($sku, -1)) % 4, 25],
        };
    }

}
