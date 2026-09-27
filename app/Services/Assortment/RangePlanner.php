<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGap;
use App\Models\AssortmentPlan;
use App\Models\Tenant;
use App\Platform\Intelligence\Substitution\DemandTransferService;
use App\Platform\Intelligence\Substitution\TransferEstimator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v1.5 Phase 2 — builds the range plans after each run: for every store ×
 * category with something to change, today's range, the engine's candidates
 * (adds, delists, stockouts to fix), the category's strategy — and the
 * optimiser's plan. A plan is a proposal; it never changes the range.
 *
 *   - Plans stay `draft` until the plan review passes (Validation → Plans);
 *     then new plans are `proposed`.
 *   - Draft and proposed plans are rebuilt every run. A plan with the same
 *     change set keeps its review verdict; a rejected change set is not
 *     proposed again; a shelf with an accepted plan in progress is left alone.
 *   - Decisions already accepted or rejected one by one are not candidates.
 */
class RangePlanner
{
    public function __construct(
        private RangeOptimizer $optimiser,
        private DemandTransferService $transfers,
        private TransferEstimator $estimator,
    ) {}

    /** @return array{plans:int, new:int, infeasible:int, shelves:int} */
    public function build(Tenant $tenant, string $asOf, array $products): array
    {
        $tenantId = (int) $tenant->id;
        $status = TenantAssortment::plansLive($tenant) ? AssortmentPlan::STATUS_PROPOSED : AssortmentPlan::STATUS_DRAFT;

        // Verdicts to keep, change sets not to propose again, shelves not to touch.
        $verdicts = AssortmentPlan::where('tenant_id', $tenantId)->whereIn('status', [AssortmentPlan::STATUS_DRAFT, AssortmentPlan::STATUS_PROPOSED])
            ->whereNotNull('review_verdict')->get(['fingerprint', 'review_verdict', 'reviewed_by', 'reviewed_at'])->keyBy('fingerprint');
        $rejected = AssortmentPlan::where('tenant_id', $tenantId)->where('status', AssortmentPlan::STATUS_REJECTED)
            ->pluck('fingerprint')->flip()->all();
        $busy = AssortmentPlan::where('tenant_id', $tenantId)->whereIn('status', [AssortmentPlan::STATUS_ACCEPTED, AssortmentPlan::STATUS_IN_PROGRESS])
            ->get(['store_id', 'category'])->mapWithKeys(fn ($p) => [$p->store_id . '|' . $p->category => true])->all();
        $before = AssortmentPlan::where('tenant_id', $tenantId)->whereIn('status', [AssortmentPlan::STATUS_DRAFT, AssortmentPlan::STATUS_PROPOSED])
            ->pluck('fingerprint')->flip()->all();
        AssortmentPlan::where('tenant_id', $tenantId)->whereIn('status', [AssortmentPlan::STATUS_DRAFT, AssortmentPlan::STATUS_PROPOSED])->delete();

        $gaps = AssortmentGap::where('tenant_id', $tenantId)
            ->whereIn('status', [AssortmentGap::STATUS_SHADOW, AssortmentGap::STATUS_OPEN])
            ->get(['id', 'store_id', 'sku', 'type', 'peer_group', 'confidence', 'confidence_tier', 'evidence', 'explanation', 'value_mid']);

        $byShelf = [];
        foreach ($gaps as $g) {
            $cat = $products[$g->sku]['category'] ?? null;
            if ($cat !== null && ! isset($busy[$g->store_id . '|' . $cat])) {
                $byShelf[$g->store_id][$cat][] = $g;
            }
        }

        $must = [];
        foreach (DB::table('assortment_must_stock')->where('tenant_id', $tenantId)->get(['sku', 'store_id', 'reason']) as $r) {
            $must[($r->store_id ?? '*') . '|' . $r->sku] = 'Must-stock' . ($r->reason ? ': ' . $r->reason : '');
        }
        $life = [];
        foreach (DB::table('product_lifecycles')->where('tenant_id', $tenantId)->whereNull('store_id')
            ->whereIn('state', ['new', 'emerging', 'seasonal'])->get(['sku', 'state']) as $r) {
            $life[(string) $r->sku] = ucfirst((string) $r->state) . ' product';
        }

        $out = ['plans' => 0, 'new' => 0, 'infeasible' => 0, 'shelves' => 0];
        $rows = [];
        foreach ($byShelf as $storeId => $categories) {
            $shelves = $this->shelves($tenantId, (int) $storeId, array_keys($categories), $asOf, $products);
            foreach ($categories as $cat => $list) {
                $out['shelves']++;
                $shelf = $shelves[$cat] ?? ['skus' => [], 'baseline' => ['sales' => 0.0, 'margin' => 0.0, 'stock' => 0.0, 'count' => 0]];
                foreach ($shelf['skus'] as $sku => $p) {
                    $shelf['skus'][$sku]['protected'] = $must[$storeId . '|' . $sku] ?? $must['*|' . $sku] ?? $life[$sku] ?? null;
                }
                $pool = (string) ($list[0]->peer_group ?? '');
                $candidates = array_values(array_filter(array_map(fn ($g) => $this->candidate($g, $products), $list)));
                $observed = $this->transfers->observed($tenantId, $pool, array_column($candidates, 'sku'));
                $transfer = fn (string $sku, array $onShelf) => $this->estimator->estimate($sku, $onShelf, $products, $observed[$sku] ?? []);

                $plan = $this->optimiser->optimise($shelf, $candidates, CategoryStrategy::for($tenant, $cat), $transfer);
                $acting = array_filter($plan['changes'], fn ($c) => $c['kind'] !== AssortmentPlan::PROTECT);
                if ($plan['feasible'] && $acting === []) {
                    continue;   // nothing worth changing on this shelf
                }
                $fingerprint = $this->fingerprint((int) $storeId, $cat, $plan);
                if (isset($rejected[$fingerprint])) {
                    continue;
                }
                $v = $verdicts[$fingerprint] ?? null;
                $out['plans']++;
                $out['new'] += isset($before[$fingerprint]) ? 0 : 1;
                $out['infeasible'] += $plan['feasible'] ? 0 : 1;
                $strategy = CategoryStrategy::for($tenant, $cat);
                $rows[] = [
                    'tenant_id' => $tenantId, 'store_id' => (int) $storeId, 'category' => mb_substr($cat, 0, 191), 'peer_group' => mb_substr($pool, 0, 64),
                    'status' => $status, 'role' => $strategy['role'], 'objective' => $strategy['objective'],
                    'feasible' => $plan['feasible'], 'infeasible_reason' => $plan['reason'],
                    'current_count' => $plan['current_count'], 'proposed_count' => $plan['proposed_count'],
                    'changes' => json_encode($plan['changes'], JSON_UNESCAPED_UNICODE),
                    'impact' => json_encode($plan['impact'] + ['margin_known' => $plan['margin_known'] ?? false], JSON_UNESCAPED_UNICODE),
                    'constraints' => json_encode($plan['constraints'] + ['left_out' => $plan['left_out']], JSON_UNESCAPED_UNICODE),
                    'value_mid' => round((float) $plan['value_mid'], 2), 'confidence' => round((float) $plan['confidence'], 4),
                    'confidence_tier' => $plan['confidence_tier'], 'fingerprint' => $fingerprint, 'optimizer_version' => $plan['version'],
                    'as_of_date' => $asOf,
                    'review_verdict' => $v?->review_verdict, 'reviewed_by' => $v?->reviewed_by, 'reviewed_at' => $v?->reviewed_at,
                    'created_at' => now(), 'updated_at' => now(),
                ];
                if (count($rows) >= 200) {
                    DB::table('assortment_plans')->insert($rows);
                    $rows = [];
                }
            }
            unset($shelves);
        }
        if ($rows !== []) {
            DB::table('assortment_plans')->insert($rows);
        }

        return $out;
    }

    /** One engine decision as an optimiser candidate. */
    private function candidate(AssortmentGap $g, array $products): ?array
    {
        $e = $g->evidence ?? [];
        $p = $products[$g->sku] ?? [];
        $kind = match ($g->type) {
            AssortmentGap::TYPE_ADD             => AssortmentPlan::ADD,
            AssortmentGap::TYPE_DELIST          => AssortmentPlan::DELIST,
            AssortmentGap::TYPE_STOCKOUT_HIDDEN => AssortmentPlan::RECOVER,
            default                             => null,
        };
        if ($kind === null) {
            return null;
        }

        return [
            'kind'          => $kind,
            'gap_id'        => (int) $g->id,
            'sku'           => (string) $g->sku,
            'name'          => (string) (($p['name'] ?? '') ?: $g->sku),
            'tier'          => (string) $g->confidence_tier,
            'confidence'    => (float) $g->confidence,
            'gross_sales'   => (float) ($e['gross_sales_per_year'] ?? 0),
            'current_sales' => (float) ($e['current_sales_per_year'] ?? 0),
            'margin_rate'   => isset($e['margin_rate']) ? (float) $e['margin_rate'] : null,
            'stock_value'   => (float) ($e['stock_value'] ?? 0),
            'units_per_day' => (float) ($e['expected_units_per_day'] ?? 0),
            'cost'          => (float) ($p['cost'] ?? 0),
        ];
    }

    /**
     * Today's range at one store for some categories, with each product's
     * sales a year (last 90 days), margin rate and stock at cost.
     *
     * @return array<string,array{skus:array,baseline:array}>
     */
    private function shelves(int $tenantId, int $storeId, array $categories, string $asOf, array $products): array
    {
        $from = Carbon::parse($asOf)->subDays(89)->toDateString();
        $marks = implode(',', array_fill(0, count($categories), '?'));
        $rows = DB::select(
            "SELECT r.sku, TRIM(p.category) AS cat,
                    COALESCE(s.rev, 0) AS rev, COALESCE(ic.value, 0) AS stock
               FROM assortment_store_ranges r
               JOIN products p ON p.tenant_id = r.tenant_id AND p.sku = r.sku
          LEFT JOIN (SELECT sd.sku, SUM(CASE WHEN sd.revenue > 0 THEN sd.revenue ELSE sd.units_sold * COALESCE(pp.selling_price, 0) END) AS rev
                       FROM sales_daily sd JOIN products pp ON pp.tenant_id = sd.tenant_id AND pp.sku = sd.sku
                      WHERE sd.tenant_id = ? AND sd.store_id = ? AND sd.date BETWEEN ? AND ?
                   GROUP BY sd.sku) s ON s.sku = r.sku
          LEFT JOIN (SELECT ic.sku, SUM(ic.on_hand_qty * COALESCE(ic.unit_cost, pc.unit_cost, 0)) AS value
                       FROM inventory_current ic LEFT JOIN products pc ON pc.tenant_id = ic.tenant_id AND pc.sku = ic.sku
                      WHERE ic.tenant_id = ? AND ic.store_id = ? AND ic.on_hand_qty > 0
                   GROUP BY ic.sku) ic ON ic.sku = r.sku
              WHERE r.tenant_id = ? AND r.store_id = ? AND r.carried AND TRIM(p.category) IN ({$marks})",
            [$tenantId, $storeId, $from, $asOf, $tenantId, $storeId, $tenantId, $storeId, ...$categories],
        );

        $out = [];
        foreach ($rows as $r) {
            $sku = (string) $r->sku;
            $pr = $products[$sku] ?? [];
            $price = (float) ($pr['price'] ?? 0);
            $cost = (float) ($pr['cost'] ?? 0);
            $rate = $price > 0 && $cost > 0 && $price > $cost ? ($price - $cost) / $price : null;
            $sales = (float) $r->rev * 365 / 90;
            $out[$r->cat]['skus'][$sku] = ['sales' => $sales, 'margin_rate' => $rate, 'stock' => (float) $r->stock, 'protected' => null,
                'name' => (string) (($pr['name'] ?? '') ?: $sku)];
        }
        foreach ($out as $cat => $shelf) {
            $s = $m = $k = 0.0;
            $anyMargin = false;
            foreach ($shelf['skus'] as $p) {
                $s += $p['sales'];
                $k += $p['stock'];
                if ($p['margin_rate'] !== null) {
                    $m += $p['sales'] * $p['margin_rate'];
                    $anyMargin = true;
                }
            }
            $out[$cat]['baseline'] = ['sales' => $s, 'margin' => $anyMargin ? $m : 0.0, 'stock' => $k, 'count' => count($shelf['skus'])];
        }

        return $out;
    }

    /** The plan's change set: the same shelf and the same changes = the same plan. */
    private function fingerprint(int $storeId, string $category, array $plan): string
    {
        $keys = array_column(array_filter($plan['changes'], fn ($c) => $c['kind'] !== AssortmentPlan::PROTECT), 'key');
        sort($keys);

        return hash('sha256', $storeId . '|' . $category . '|' . implode(',', $keys) . '|' . ($plan['feasible'] ? '1' : '0'));
    }
}
