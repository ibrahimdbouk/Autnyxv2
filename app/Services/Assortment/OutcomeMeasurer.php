<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGap;
use App\Models\Store;
use App\Models\StoreCluster;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A5 — did the range change work? Measured against peer stores that did NOT
 * make it (difference-in-differences), not as a raw before/after, so category
 * growth, promotions, weather and seasonality that hit every store alike cancel out.
 *
 *   window      `measure_days` (56) before the task was done, and 56 after
 *   metric      add / delist → the store's CATEGORY sales (an add that only
 *               moves sales from similar products shows no uplift; a delist
 *               whose sales move to the rest of the shelf shows no loss);
 *               stockout-hidden → the product's own sales
 *   control     the other stores of the same peer group without an accepted
 *               decision on the same product
 *   expected    treated store's "before" × the controls' after/before ratio
 *   uplift      treated store's "after" − expected   (per 8 weeks, and per year)
 *
 * Fewer than 3 controls → recorded, flagged "weak".
 *
 * Learning record (v1.5): the measurement keeps the change in sales a year that
 * was EXPECTED when the decision was made (and the transferable-demand model
 * version behind it), the error, and a verdict — worked / partly / did not —
 * which is written back to the platform decision memory. Measured is never
 * mixed up with expected: both are stored, labelled, side by side.
 */
class OutcomeMeasurer
{
    public function __construct(
        private ?DecisionLearning $learning = null,
        private ?AssortmentNotifier $notifier = null,
    ) {}

    /** @return array{measured:int, waiting:int} */
    public function measureDue(int $tenantId): array
    {
        $asOf = app(RangeModel::class)->asOfDate($tenantId);
        if ($asOf === null) {
            return ['measured' => 0, 'waiting' => 0];
        }
        $days = (int) config('assortment.measure_days', 56);
        // The "after" window needs data through done + days.
        $due = AssortmentGap::query()->where('tenant_id', $tenantId)
            ->where('status', AssortmentGap::STATUS_ACCEPTED)
            ->where('task_status', AssortmentGap::TASK_DONE)
            ->whereNull('measured_at')
            ->whereNotNull('measure_after')
            ->get();

        $measured = $waiting = 0;
        foreach ($due as $gap) {
            if ($gap->measure_after->toDateString() > $asOf) {
                $waiting++;

                continue;
            }
            $gap->forceFill(['measurement' => $this->measure($gap, $days), 'measured_at' => now()])->save();
            ($this->learning ?? app(DecisionLearning::class))->measured($gap);
            ($this->notifier ?? app(AssortmentNotifier::class))->measured($gap);
            $measured++;
        }

        return ['measured' => $measured, 'waiting' => $waiting];
    }

    /** @return array<string,mixed> */
    public function measure(AssortmentGap $gap, int $days): array
    {
        $done    = Carbon::parse($gap->done_at)->startOfDay();
        $pre     = [$done->copy()->subDays($days)->toDateString(), $done->copy()->subDay()->toDateString()];
        $post    = [$done->copy()->addDay()->toDateString(), $done->copy()->addDays($days)->toDateString()];
        $product = DB::table('products')->where('tenant_id', $gap->tenant_id)->where('sku', $gap->sku)->first(['category', 'selling_price', 'unit_cost']);

        $byCategory = $gap->type !== AssortmentGap::TYPE_STOCKOUT_HIDDEN && $product?->category;
        $skus = $byCategory
            ? DB::table('products')->where('tenant_id', $gap->tenant_id)->where('category', $product->category)->pluck('sku')->all()
            : [$gap->sku];

        $controls = array_values(array_diff($this->peerStores($gap), [$gap->store_id], $this->storesWithSameDecision($gap)));

        $sum = function (array $stores, array $range) use ($gap, $skus): float {
            if ($stores === [] || $skus === []) {
                return 0.0;
            }

            return (float) DB::table('sales_daily')->where('tenant_id', $gap->tenant_id)
                ->whereIn('store_id', $stores)->whereIn('sku', $skus)
                ->whereBetween('date', $range)
                ->selectRaw('COALESCE(SUM(CASE WHEN revenue > 0 THEN revenue ELSE 0 END), 0) AS r')->value('r');
        };

        $treatedPre  = $sum([$gap->store_id], $pre);
        $treatedPost = $sum([$gap->store_id], $post);
        $controlPre  = $sum($controls, $pre);
        $controlPost = $sum($controls, $post);
        $ratio       = $controlPre > 0 ? $controlPost / $controlPre : 1.0;
        $expected    = $treatedPre * $ratio;
        $uplift      = $treatedPost - $expected;

        $upliftYear = round($uplift * 365 / max(1, $days), 2);
        $expectedYear = isset($gap->evidence['expected_sales_change_per_year']) ? (float) $gap->evidence['expected_sales_change_per_year'] : null;
        $strength = count($controls) >= 3 ? 'measured' : 'weak';

        return [
            'metric'          => $byCategory ? 'category_sales' : 'product_sales',
            'category'        => $product?->category,
            'days'            => $days,
            'before'          => $pre,
            'after'           => $post,
            'treated_before'  => round($treatedPre, 2),
            'treated_after'   => round($treatedPost, 2),
            'control_stores'  => count($controls),
            'control_ratio'   => round($ratio, 4),
            'expected_after'  => round($expected, 2),
            'uplift'          => round($uplift, 2),
            'uplift_per_year' => $upliftYear,
            'strength'        => $strength,
            'expected_per_year' => $expectedYear,
            'error_per_year'  => $expectedYear === null ? null : round($upliftYear - $expectedYear, 2),
            'transfer_version' => $gap->evidence['transfer']['version'] ?? null,
            'verdict'         => $this->verdict($gap->type, $upliftYear, $expectedYear, $strength),
        ];
    }

    /**
     * Did it work? An add or a recovered stockout worked when category (or
     * product) sales rose by at least half of what was expected, partly when
     * they rose at all. A delist worked when sales fell no more than expected,
     * partly when no more than twice that. Few comparison stores → at best partly.
     */
    public function verdict(string $type, float $uplift, ?float $expected, string $strength): string
    {
        if ($type === AssortmentGap::TYPE_DELIST) {
            $loss = $expected !== null ? min(0.0, $expected) : 0.0;
            $v = $uplift >= $loss ? 'success' : ($uplift >= 2 * $loss ? 'partial' : 'failure');
        } else {
            $v = $expected !== null && $expected > 0 && $uplift >= 0.5 * $expected ? 'success' : ($uplift > 0 ? 'partial' : 'failure');
        }

        return $strength === 'weak' && $v === 'success' ? 'partial' : $v;
    }

    /** The stores of the decision's peer group (cluster:<id> or format:<slug>). */
    private function peerStores(AssortmentGap $gap): array
    {
        [$basis, $key] = array_pad(explode(':', (string) $gap->peer_group, 2), 2, null);
        if ($basis === 'cluster' && is_numeric($key)) {
            $cluster = StoreCluster::with('stores:id')->find((int) $key);
            if ($cluster) {
                return $cluster->stores->pluck('id')->map(fn ($id) => (int) $id)->all();
            }
        }
        if ($basis === 'format' && $key !== null) {
            return Store::where('tenant_id', $gap->tenant_id)->get(['id', 'format'])
                ->filter(fn ($s) => (Str::slug(trim((string) $s->format)) ?: 'unspecified') === $key)
                ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }

        return [];
    }

    private function storesWithSameDecision(AssortmentGap $gap): array
    {
        return AssortmentGap::where('tenant_id', $gap->tenant_id)->where('sku', $gap->sku)
            ->where('status', AssortmentGap::STATUS_ACCEPTED)->whereKeyNot($gap->id)
            ->pluck('store_id')->map(fn ($id) => (int) $id)->all();
    }
}
