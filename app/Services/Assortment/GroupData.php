<?php

namespace App\Services\Assortment;

use App\Platform\Intelligence\Availability\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the benchmark and the gap engine need about one peer group, for one
 * CHUNK of SKUs at a time (so a 50-store × 40,000-SKU group never has to sit in
 * memory at once): what each member store carries, how each carried product sold
 * and how often it was in stock over the window. Each store's category sales —
 * the size normaliser — are read once per group with `categoryRevenue()`.
 *
 * For every carried (store, SKU) it derives the store's own performance:
 *   in-stock days  = days carried in the window × availability (1.0 when the
 *                    stock history has no observation)
 *   rate           = units (and revenue) per in-stock day
 *   share index    = revenue per in-stock day ÷ the store's category revenue per day
 */
class GroupData
{
    /** @var array<int,array<string,array{carried:bool,first_seen:?string}>> store => sku => range */
    public array $ranges = [];

    /** @var array<int,array<string,array<string,mixed>>> store => sku => performance (carried products only) */
    public array $perf = [];

    /** @var array<string,array<string,mixed>> "store|sku" => availability row */
    public array $availability = [];

    public int $windowDays;

    public string $from;

    /**
     * @param  array<int,int>  $members
     * @param  array<string,array<string,mixed>>  $products  sku => product facts
     * @param  array<int,array<string,float>>  $categoryRevenuePerDay  store => category => revenue per day
     * @param  array<int,string>  $skus  the chunk of SKUs to load
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly array $members,
        public readonly string $asOf,
        private readonly array $products,
        public readonly array $categoryRevenuePerDay = [],
        public readonly array $skus = [],
    ) {
        $this->windowDays = self::window();
        $this->from = Carbon::parse($asOf)->subDays($this->windowDays - 1)->toDateString();
    }

    public static function window(): int
    {
        return max(28, (int) config('assortment.benchmark_window_days', 90));
    }

    /**
     * Each member store's revenue per day by category over the window — one
     * aggregate query per group (a few rows per store).
     *
     * @return array<int,array<string,float>>
     */
    public static function categoryRevenue(int $tenantId, array $members, string $asOf): array
    {
        if ($members === []) {
            return [];
        }
        $window = self::window();
        $from = Carbon::parse($asOf)->subDays($window - 1)->toDateString();
        $in = implode(',', array_map('intval', $members));
        $out = [];
        foreach (DB::select(
            "SELECT sd.store_id, TRIM(p.category) AS category,
                    SUM(CASE WHEN sd.revenue > 0 THEN sd.revenue ELSE sd.units_sold * COALESCE(p.selling_price, 0) END) AS rev
               FROM sales_daily sd
               JOIN products p ON p.tenant_id = sd.tenant_id AND p.sku = sd.sku
              WHERE sd.tenant_id = ? AND sd.store_id IN ({$in}) AND sd.date BETWEEN ? AND ?
                AND p.category IS NOT NULL AND TRIM(p.category) <> ''
           GROUP BY sd.store_id, TRIM(p.category)",
            [$tenantId, $from, $asOf],
        ) as $r) {
            $out[(int) $r->store_id][(string) $r->category] = ((float) $r->rev) / $window;
        }

        return $out;
    }

    /**
     * Every SKU any member carries — the group's SKU universe, to be chunked.
     *
     * @return array<int,string>
     */
    public static function groupSkus(int $tenantId, array $members): array
    {
        if ($members === []) {
            return [];
        }

        return DB::table('assortment_store_ranges')->where('tenant_id', $tenantId)
            ->whereIn('store_id', $members)->where('carried', true)
            ->distinct()->orderBy('sku')->pluck('sku')->map(fn ($s) => (string) $s)->all();
    }

    public function load(AvailabilityService $availability): self
    {
        if ($this->members === [] || $this->skus === []) {
            return $this;
        }
        $in = implode(',', array_map('intval', $this->members));
        $skuMarks = implode(',', array_fill(0, count($this->skus), '?'));

        foreach (DB::select(
            "SELECT store_id, sku, carried, first_seen FROM assortment_store_ranges
              WHERE tenant_id = ? AND store_id IN ({$in}) AND sku IN ({$skuMarks})",
            [$this->tenantId, ...$this->skus],
        ) as $r) {
            $this->ranges[(int) $r->store_id][(string) $r->sku] = [
                'carried'    => (bool) $r->carried,
                'first_seen' => $r->first_seen,
            ];
        }

        $sales = [];
        foreach (DB::select(
            "SELECT store_id, sku, SUM(units_sold) AS units, SUM(revenue) AS revenue
               FROM sales_daily
              WHERE tenant_id = ? AND store_id IN ({$in}) AND sku IN ({$skuMarks}) AND date BETWEEN ? AND ?
           GROUP BY store_id, sku",
            [$this->tenantId, ...$this->skus, $this->from, $this->asOf],
        ) as $r) {
            $sku   = (string) $r->sku;
            $units = (float) $r->units;
            $rev   = (float) $r->revenue;
            if ($rev <= 0 && $units > 0) {
                $rev = $units * (float) ($this->products[$sku]['price'] ?? 0);   // feeds without revenue
            }
            $sales[(int) $r->store_id][$sku] = [$units, $rev];
        }

        $this->availability = $availability->forWindow($this->tenantId, $this->from, $this->asOf, $this->members, $this->skus);

        $asOf = Carbon::parse($this->asOf);
        foreach ($this->ranges as $storeId => $skus) {
            foreach ($skus as $sku => $range) {
                if (! $range['carried']) {
                    continue;
                }
                $firstSeen   = $range['first_seen'] ? Carbon::parse($range['first_seen']) : $asOf;
                $carriedDays = (int) $firstSeen->diffInDays($asOf) + 1;
                $windowDays  = min($this->windowDays, $carriedDays);
                $av          = $this->availability[$storeId . '|' . $sku] ?? null;
                $avail       = $av['availability'] ?? null;
                $inStockDays = max(1.0, $windowDays * ($avail ?? 1.0));
                [$units, $rev] = $sales[$storeId][$sku] ?? [0.0, 0.0];
                $cat         = $this->products[$sku]['category'] ?? null;
                $catPerDay   = $cat !== null ? ($this->categoryRevenuePerDay[$storeId][$cat] ?? 0.0) : 0.0;
                $revPerDay   = $rev / $inStockDays;

                $this->perf[$storeId][$sku] = [
                    'carried_days'   => $carriedDays,
                    'window_days'    => $windowDays,
                    'availability'   => $avail,
                    'observations'   => (int) ($av['observations'] ?? 0),
                    'units_per_day'  => $units / $inStockDays,
                    'rev_per_day'    => $revPerDay,
                    'share'          => $catPerDay > 0 ? $revPerDay / $catPerDay : null,
                ];
            }
        }
        $this->availability = [];   // folded into perf; free it

        return $this;
    }

    public function carries(int $storeId, string $sku): bool
    {
        return (bool) ($this->ranges[$storeId][$sku]['carried'] ?? false);
    }

    /** Does this member store qualify as a peer for the SKU (carried long enough, usually in stock)? */
    public function qualifies(int $storeId, string $sku): bool
    {
        $p = $this->perf[$storeId][$sku] ?? null;
        if ($p === null || $p['share'] === null) {
            return false;
        }
        if ($p['carried_days'] < (int) config('assortment.peer_min_carried_days', 28)) {
            return false;
        }

        return $p['availability'] === null || $p['availability'] >= (float) config('assortment.peer_min_availability', 0.80);
    }

    /** @return array<int,string> every SKU of this chunk any member carries */
    public function carriedSkus(): array
    {
        $out = [];
        foreach ($this->perf as $skus) {
            foreach (array_keys($skus) as $sku) {
                $out[$sku] = true;
            }
        }

        return array_map('strval', array_keys($out));
    }
}
