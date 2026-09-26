<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGap;
use App\Models\Store;
use App\Models\StoreCluster;
use App\Platform\Intelligence\Availability\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The audit trail behind one decision: every store of its peer group, whether
 * it carries the product, and how it sold and stayed in stock over the same
 * window the engine used. Opened when someone doubts a call.
 */
class PeerDetail
{
    public function __construct(private AvailabilityService $availability) {}

    /** @return array<int,array<string,mixed>> */
    public function for(AssortmentGap $gap): array
    {
        $members = $this->members($gap);
        if ($members === []) {
            return [];
        }
        $asOf   = $gap->as_of_date?->toDateString() ?? now()->toDateString();
        $window = (int) ($gap->evidence['window_days'] ?? config('assortment.benchmark_window_days', 90));
        $from   = Carbon::parse($asOf)->subDays(max(1, $window) - 1)->toDateString();

        $names  = Store::whereIn('id', $members)->pluck('name', 'id');
        $ranges = DB::table('assortment_store_ranges')->where('tenant_id', $gap->tenant_id)
            ->where('sku', $gap->sku)->whereIn('store_id', $members)->get()->keyBy('store_id');
        $sales  = DB::table('sales_daily')->where('tenant_id', $gap->tenant_id)->where('sku', $gap->sku)
            ->whereIn('store_id', $members)->whereBetween('date', [$from, $asOf])
            ->groupBy('store_id')->selectRaw('store_id, SUM(units_sold) AS units')->pluck('units', 'store_id');
        $avail  = $this->availability->forWindow((int) $gap->tenant_id, $from, $asOf, $members);

        $rows = [];
        foreach ($members as $id) {
            $range   = $ranges[$id] ?? null;
            $carried = (bool) ($range->carried ?? false);
            $a       = $avail[$id . '|' . $gap->sku]['availability'] ?? null;
            $first   = $range?->first_seen ? Carbon::parse($range->first_seen) : null;
            $days    = $first ? min($window, (int) $first->diffInDays(Carbon::parse($asOf)) + 1) : $window;
            $inStock = max(1.0, $days * ($a ?? 1.0));
            $rows[] = [
                'store_id'      => $id,
                'store'         => (string) ($names[$id] ?? "#{$id}"),
                'this_store'    => $id === (int) $gap->store_id,
                'carried'       => $carried,
                'since'         => $first?->toDateString(),
                'availability'  => $a,
                'units_per_day' => $carried ? round(((float) ($sales[$id] ?? 0)) / $inStock, 2) : null,
            ];
        }
        usort($rows, fn ($x, $y) => [$y['this_store'], $y['carried'], $y['units_per_day'] ?? -1] <=> [$x['this_store'], $x['carried'], $x['units_per_day'] ?? -1]);

        return $rows;
    }

    /** @return array<int,int> */
    private function members(AssortmentGap $gap): array
    {
        [$basis, $key] = array_pad(explode(':', (string) $gap->peer_group, 2), 2, null);
        if ($basis === 'cluster' && is_numeric($key)) {
            return StoreCluster::with('stores:id')->find((int) $key)?->stores->pluck('id')->map(fn ($i) => (int) $i)->all() ?? [];
        }
        if ($basis === 'format') {
            return Store::where('tenant_id', $gap->tenant_id)->get(['id', 'format'])
                ->filter(fn ($s) => (Str::slug(trim((string) $s->format)) ?: 'unspecified') === $key)
                ->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
        }

        return [];
    }
}
