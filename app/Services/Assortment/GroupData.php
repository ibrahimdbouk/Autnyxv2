<?php

namespace App\Services\Assortment;

use App\Platform\Intelligence\Availability\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything the benchmark and the gap engine need about one peer group, read
 * once: what each member store carries, how each carried product sold and how
 * often it was in stock over the window, and each store's category sales.
 *
 * For every carried (store, SKU) it derives the store's own performance:
 *   in-stock days  = days carried in the window × availability (1.0 when the
 *                    stock history has no observation — flagged as such)
 *   rate           = units (and revenue) per in-stock day
 *   share index    = revenue per in-stock day ÷ the store's category revenue per day
 * The share index is what makes a small store comparable with a big one.
 */
class GroupData
{
    /** @var array<int,array<string,array{carried:bool,first_seen:?string}>> store => sku => range */
    public array $ranges = [];

    /** @var array<int,array<string,array<string,mixed>>> store => sku => performance (carried products only) */
    public array $perf = [];

    /** @var array<int,array<string,float>> store => category => revenue per day */
    public array $categoryRevenuePerDay = [];

    /** @var array<string,array<string,mixed>> "store|sku" => availability row */
    public array $availability = [];

    public int $windowDays;

    public string $from;

    /**
     * @param  array<int,int>  $members
     * @param  array<string,array<string,mixed>>  $products  sku => product facts
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly array $members,
        public readonly string $asOf,
        private readonly array $products,
    ) {
        $this->windowDays = max(28, (int) config('assortment.benchmark_window_days', 90));
        $this->from = Carbon::parse($asOf)->subDays($this->windowDays - 1)->toDateString();
    }

    public function load(AvailabilityService $availability): self
    {
        if ($this->members === []) {
            return $this;
        }
        $in = implode(',', array_map('intval', $this->members));

        foreach (DB::select(
            "SELECT store_id, sku, carried, first_seen FROM assortment_store_ranges
              WHERE tenant_id = ? AND store_id IN ({$in})",
            [$this->tenantId],
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
              WHERE tenant_id = ? AND store_id IN ({$in}) AND date BETWEEN ? AND ?
           GROUP BY store_id, sku",
            [$this->tenantId, $this->from, $this->asOf],
        ) as $r) {
            $sku   = (string) $r->sku;
            $units = (float) $r->units;
            $rev   = (float) $r->revenue;
            if ($rev <= 0 && $units > 0) {
                $rev = $units * (float) ($this->products[$sku]['price'] ?? 0);   // feeds without revenue
            }
            $sales[(int) $r->store_id][$sku] = ['units' => $units, 'revenue' => $rev];

            $cat = $this->products[$sku]['category'] ?? null;
            if ($cat !== null) {
                $this->categoryRevenuePerDay[(int) $r->store_id][$cat] =
                    ($this->categoryRevenuePerDay[(int) $r->store_id][$cat] ?? 0.0) + $rev / $this->windowDays;
            }
        }

        $this->availability = $availability->forWindow($this->tenantId, $this->from, $this->asOf, $this->members);

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
                $s           = $sales[$storeId][$sku] ?? ['units' => 0.0, 'revenue' => 0.0];
                $cat         = $this->products[$sku]['category'] ?? null;
                $catPerDay   = $cat !== null ? ($this->categoryRevenuePerDay[$storeId][$cat] ?? 0.0) : 0.0;
                $revPerDay   = $s['revenue'] / $inStockDays;

                $this->perf[$storeId][$sku] = [
                    'carried_days'   => $carriedDays,
                    'window_days'    => $windowDays,
                    'availability'   => $avail,
                    'observations'   => (int) ($av['observations'] ?? 0),
                    'in_stock_days'  => $inStockDays,
                    'units'          => $s['units'],
                    'revenue'        => $s['revenue'],
                    'units_per_day'  => $s['units'] / $inStockDays,
                    'rev_per_day'    => $revPerDay,
                    'share'          => $catPerDay > 0 ? $revPerDay / $catPerDay : null,
                ];
            }
        }

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

    /** @return array<int,string> every SKU any member carries */
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
