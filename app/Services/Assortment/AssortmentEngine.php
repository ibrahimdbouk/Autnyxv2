<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGap;
use App\Models\AssortmentRun;
use App\Models\DataContract;
use App\Models\Tenant;
use App\Platform\Intelligence\Availability\AvailabilityService;
use App\Platform\Intelligence\Lifecycle\ProductLifecycleService;
use App\Platform\Intelligence\Promotions\PromotionCalendar;
use App\Platform\Intelligence\Substitution\DemandTransferService;
use App\Platform\Trust\DataConfidence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assortment Intelligence — one tenant's run, end to end:
 *
 *   range model (A1) → lifecycle (platform) → peer groups → per group:
 *   benchmark (A2, promotion-clean) → transferable demand (platform) → decisions (A3)
 *   → range plans per store × category (v1.5 Phase 2, RangePlanner + RangeOptimizer)
 *
 * Decisions are stored with status 'shadow' while `assortment.shadow` is on:
 * computed, explainable, reviewable with `assortment:review`, but not shown to
 * users until the validation gate passes. Runs for tenants that hold the
 * Assortment app or are flagged for a shadow run (settings.assortment.shadow_run).
 */
class AssortmentEngine
{

    public function __construct(
        private RangeModel $ranges,
        private PeerGroups $peerGroups,
        private AvailabilityService $availability,
        private DataConfidence $trust,
        private ProductLifecycleService $lifecycle,
        private DemandTransferService $transfers,
        private AssortmentNotifier $notifier,
        private RangePlanner $planner,
    ) {}

    public static function enabledFor(Tenant $tenant): bool
    {
        $settings = is_array($tenant->settings) ? $tenant->settings : [];

        return $tenant->hasApp(Tenant::APP_ASSORTMENT) || (($settings['assortment']['shadow_run'] ?? false) === true);
    }

    public function run(Tenant $tenant, bool $force = false): AssortmentRun
    {
        $started = now();
        $run = AssortmentRun::create(['tenant_id' => $tenant->id, 'status' => AssortmentRun::STATUS_SKIPPED, 'started_at' => $started]);

        if (! $force && ! static::enabledFor($tenant)) {
            $run->update(['stats' => ['reason' => 'not enabled for this tenant'], 'finished_at' => now()]);

            return $run;
        }

        try {
            $stats = $this->execute($tenant, $started);
            $run->update([
                'status'      => isset($stats['reason']) ? AssortmentRun::STATUS_SKIPPED : AssortmentRun::STATUS_SUCCESS,
                'as_of_date'  => $stats['as_of'] ?? null,
                'stats'       => $stats,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $run->update(['status' => AssortmentRun::STATUS_FAILED, 'stats' => ['error' => mb_substr($e->getMessage(), 0, 500)], 'finished_at' => now()]);

            throw $e;
        }

        return $run;
    }

    /** @return array<string,mixed> */
    private function execute(Tenant $tenant, \DateTimeInterface $started): array
    {
        $tenantId = (int) $tenant->id;
        $asOf = $this->ranges->asOfDate($tenantId);
        if ($asOf === null) {
            return ['reason' => 'no sales or stock data yet'];
        }

        $guards   = TenantAssortment::guardrails($tenant);
        $range    = $this->ranges->rebuild($tenantId, $asOf, $guards['carried_window_days']);
        $life     = $this->lifecycle->rebuild($tenantId, $asOf);
        $peers    = $this->peerGroups->build($tenantId);
        $products = $this->products($tenantId);
        [$factor, $reasons] = $this->dataQuality($tenantId);
        $allowDelists = $range['history_days'] >= (int) config('assortment.delist_min_history_days', 182);
        // Promotions from the calendar and the sales lines; a year back tells whether the tenant sends any at all.
        $promos = PromotionCalendar::load($tenantId, Carbon::parse($asOf)->subDays(400)->toDateString(), $asOf);
        $hasPromotionData = ! $promos->isEmpty();
        $transferFrom = Carbon::parse($asOf)->subDays(max(28, (int) config('assortment.transfer_window_days', 182)) - 1)->toDateString();

        $engine = (new GapEngine($products))->withContext(
            mustStock: $this->mustStock($tenantId),
            stockValue: $this->stockValue($tenantId),
            storeNames: DB::table('stores')->where('tenant_id', $tenantId)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all(),
            qualityFactor: $factor,
            qualityReasons: $reasons,
            allowDelists: $allowDelists,
            currency: $tenant->currencyCode(),
            delistMinTier: $guards['delist_min_tier'],
            maxDelistsPerCategory: $guards['max_delists_per_category'],
            lifecycle: $this->lifecycle->chain($tenantId),
            hasPromotionData: $hasPromotionData,
        );
        $live   = TenantAssortment::isLive($tenant) || ! config('assortment.shadow', true);
        $status = $live ? AssortmentGap::STATUS_OPEN : AssortmentGap::STATUS_SHADOW;
        // Decisions a person already accepted or rejected are never rewritten by a later run.
        $decided = AssortmentGap::where('tenant_id', $tenantId)
            ->whereIn('status', [AssortmentGap::STATUS_ACCEPTED, AssortmentGap::STATUS_REJECTED])
            ->get(['store_id', 'sku', 'type'])
            ->mapWithKeys(fn ($g) => ["{$g->store_id}|{$g->sku}|{$g->type}" => true])->all();

        DB::table('assortment_benchmarks')->where('tenant_id', $tenantId)->delete();

        $counts = ['add' => 0, 'delist' => 0, 'stockout_hidden' => 0, 'skipped_no_category_sales' => 0, 'skipped_speculative_delist' => 0,
            'skipped_lifecycle' => 0, 'capped_delists' => 0, 'capped_adds' => 0];
        $transferStats = ['pairs' => 0, 'from_stockouts' => 0, 'from_range_changes' => 0, 'seconds' => 0];
        $benchmarks = 0;
        $health = [];
        $found  = [];
        $groupsOut = [];
        foreach ($peers['groups'] as $key => $group) {
            $targets = array_keys(array_filter($peers['assignment'], fn ($k) => $k === $key));
            if ($targets === []) {
                continue;
            }
            // The group is read one chunk of SKUs at a time, twice: first to build
            // the benchmark (and each store's own share index), then to judge.
            $catRev = GroupData::categoryRevenue($tenantId, $group['members'], $asOf);
            $chunks = array_chunk(GroupData::groupSkus($tenantId, $group['members']), max(1, (int) config('assortment.chunk_skus', 400)));
            $load   = fn (array $skus) => (new GroupData($tenantId, $group['members'], $asOf, $products, $catRev, $skus, $promos))->load($this->availability);

            $bench = $shares = $carried = [];
            foreach ($chunks as $skus) {
                $data = $load($skus);
                $bench += $engine->benchmark($data);
                foreach ($targets as $storeId) {
                    foreach ($data->perf[$storeId] ?? [] as $sku => $p) {
                        $carried[$storeId][$sku] = true;
                        if ($p['share'] !== null) {
                            $shares[$storeId][$sku] = $p['share'];
                        }
                    }
                }
                unset($data);
            }
            $benchmarks += $this->saveBenchmarks($tenantId, $group, $bench, $asOf, GroupData::window());

            // Where each product's buyers go when it is not on the shelf, measured across the group.
            $clock = microtime(true);
            $t = $this->transfers->rebuild($tenantId, $key, $group['members'], $transferFrom, $asOf, $promos);
            foreach (['pairs', 'from_stockouts', 'from_range_changes'] as $k) {
                $transferStats[$k] += $t[$k];
            }
            $transferStats['seconds'] += (int) round(microtime(true) - $clock);

            $prepared    = $engine->prepare($bench, $shares, $targets);
            $prepared['shelf'] = $engine->shelves($carried);
            $groupCounts = array_fill_keys(array_keys($counts), 0);
            $adds        = new TopPerShelf($guards['max_adds_per_category']);
            $delists     = new TopPerShelf($guards['max_delists_per_category']);
            $stockouts   = [];
            // Stockout-hidden decisions are saved chunk by chunk; adds and delists
            // keep only the most valuable few per store × category.
            $keep = function (array $gaps) use ($tenantId, $asOf, $status, $decided, &$found) {
                foreach ($gaps as $g) {
                    $found["{$g['store_id']}|{$g['sku']}|{$g['type']}"] = true;
                }
                $this->saveGaps($tenantId, array_values(array_filter($gaps, fn ($g) => ! isset($decided["{$g['store_id']}|{$g['sku']}|{$g['type']}"]))), $asOf, $status);
            };
            foreach ($chunks as $skus) {
                $now = [];
                $engine->withTransfers($this->transfers->observed($tenantId, $key, $skus));
                foreach ($engine->evaluateChunk($load($skus), $targets, $bench, $prepared, $group, $groupCounts) as $gap) {
                    $shelf = $gap['store_id'] . '|' . $engine->categoryOf($gap['sku']);
                    match ($gap['type']) {
                        AssortmentGap::TYPE_ADD    => $adds->offer($shelf, $gap),
                        AssortmentGap::TYPE_DELIST => $delists->offer($shelf, $gap),
                        default                    => $now[] = $gap,
                    };
                }
                foreach ($now as $gap) {
                    $cat = (string) $engine->categoryOf($gap['sku']);
                    $stockouts[$cat] = ($stockouts[$cat] ?? 0) + 1;
                }
                $groupCounts[AssortmentGap::TYPE_STOCKOUT_HIDDEN] += count($now);
                $keep($now);
                unset($now);
            }
            $keep($adds->all());
            $keep($delists->all());
            $groupCounts[AssortmentGap::TYPE_ADD]    += count($adds->all());
            $groupCounts[AssortmentGap::TYPE_DELIST] += count($delists->all());
            $groupCounts['capped_adds']              += $adds->dropped();
            $groupCounts['capped_delists']           += $delists->dropped();
            $result = ['health' => $engine->finalize($targets, $bench, $group, $shares, $carried, $stockouts)];
            unset($bench, $shares, $carried, $prepared, $adds, $delists);
            foreach ($groupCounts as $k => $n) {
                $counts[$k] += $n;
            }
            array_push($health, ...$result['health']);
            $groupsOut[] = ['key' => $key, 'label' => $group['label'], 'basis' => $group['basis'], 'stores' => count($group['members']), 'judged' => count($targets)];
        }

        // Decisions not found again this run are gone (accepted / rejected ones are kept).
        $stale = AssortmentGap::query()->where('tenant_id', $tenantId)
            ->whereIn('status', [AssortmentGap::STATUS_SHADOW, AssortmentGap::STATUS_OPEN])
            ->get(['id', 'store_id', 'sku', 'type'])
            ->reject(fn ($g) => isset($found["{$g->store_id}|{$g->sku}|{$g->type}"]))
            ->pluck('id');
        foreach ($stale->chunk(1000) as $ids) {
            AssortmentGap::whereIn('id', $ids->all())->delete();
        }
        $removed = $stale->count();

        $coverage = $this->availability->coverage($tenantId);

        // v1.5 Phase 2 — the engine's decisions, chosen together per store × category.
        $plans = $this->planner->build($tenant, $asOf, $products);
        if (TenantAssortment::plansLive($tenant)) {
            $this->notifier->newPlans($tenant, $plans['new']);
        }

        // Live tenants hear about decisions found for the first time in this run.
        if ($live) {
            $new = AssortmentGap::query()->where('tenant_id', $tenantId)->where('status', AssortmentGap::STATUS_OPEN)
                ->where('first_detected_at', '>=', $started);
            $this->notifier->newDecisions($tenant, (clone $new)->count(), (float) (clone $new)->sum('value_mid'));
        }

        return [
            'as_of'          => $asOf,
            'shadow'         => ! $live,
            'guardrails'     => $guards,
            'health'         => $health,
            'range'          => $range,
            'stock_history'  => $coverage,
            'peer_groups'    => $groupsOut,
            'stores_unjudged' => count($peers['unjudged']),
            'benchmarks'     => $benchmarks,
            'decisions'      => $counts,
            'removed'        => $removed,
            'delists_allowed' => $allowDelists,
            'data_quality'   => ['factor' => $factor, 'reasons' => $reasons],
            'lifecycle'      => $life,
            'plans'          => $plans,
            'promotion_data' => $hasPromotionData,
            'transfers'      => $transferStats + ['version' => DemandTransferService::VERSION],
        ];
    }

    /** @return array<string,array<string,mixed>> sku => facts */
    private function products(int $tenantId): array
    {
        return ProductFacts::load($tenantId);
    }

    /** @return array<string,true> "storeId|sku", or "*|sku" for every store */
    private function mustStock(int $tenantId): array
    {
        $out = [];
        foreach (DB::table('assortment_must_stock')->where('tenant_id', $tenantId)->get(['sku', 'store_id']) as $r) {
            $out[($r->store_id ?? '*') . '|' . $r->sku] = true;
        }

        return $out;
    }

    /** @return array<string,float> "storeId|sku" => on hand × cost */
    private function stockValue(int $tenantId): array
    {
        $out = [];
        foreach (DB::select(
            'SELECT ic.store_id, ic.sku,
                    SUM(ic.on_hand_qty * COALESCE(ic.unit_cost, p.unit_cost, 0)) AS value
               FROM inventory_current ic
          LEFT JOIN products p ON p.tenant_id = ic.tenant_id AND p.sku = ic.sku
              WHERE ic.tenant_id = ? AND ic.on_hand_qty > 0
           GROUP BY ic.store_id, ic.sku',
            [$tenantId],
        ) as $r) {
            $out[$r->store_id . '|' . $r->sku] = (float) $r->value;
        }

        return $out;
    }

    /** Confidence discount from open data-contract breaches on the sales and stock feeds. */
    private function dataQuality(int $tenantId): array
    {
        $feeds = DataContract::query()->where('tenant_id', $tenantId)->pluck('feed_key')
            ->filter(fn ($k) => str_contains(strtolower((string) $k), 'sales') || str_contains(strtolower((string) $k), 'inv'))
            ->values()->all();
        if ($feeds === []) {
            return [1.0, []];
        }

        return [$this->trust->qualityFactor($tenantId, $feeds), $this->trust->reasons($tenantId, $feeds)];
    }

    private function saveBenchmarks(int $tenantId, array $group, array $bench, string $asOf, int $windowDays): int
    {
        $now = now();
        $rows = [];
        foreach ($bench as $b) {
            $rows[] = [
                'tenant_id' => $tenantId, 'peer_group' => $group['key'], 'peer_basis' => $group['basis'],
                'sku' => $b['sku'], 'product_id' => $b['product_id'], 'group_size' => $b['group_size'],
                'carrying' => $b['carrying'], 'qualifying' => $b['qualifying'], 'carried_share' => $b['carried_share'],
                'share_index_p25' => $b['share_index_p25'], 'share_index_median' => $b['share_index_median'],
                'share_index_p75' => $b['share_index_p75'], 'units_per_day_median' => $b['units_per_day_median'],
                'revenue_per_day_median' => $b['revenue_per_day_median'], 'availability_median' => $b['availability_median'],
                'as_of_date' => $asOf, 'window_days' => $windowDays, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('assortment_benchmarks')->insert($chunk);
        }

        return count($rows);
    }

    private function saveGaps(int $tenantId, array $gaps, string $asOf, string $status): void
    {
        $now    = now();
        $rows   = [];
        foreach ($gaps as $g) {
            $rows[] = [
                'tenant_id' => $tenantId, 'store_id' => $g['store_id'], 'sku' => $g['sku'], 'product_id' => $g['product_id'],
                'type' => $g['type'], 'status' => $status, 'peer_group' => $g['peer_group'],
                'value_low' => $g['value_low'], 'value_mid' => $g['value_mid'], 'value_high' => $g['value_high'],
                'confidence' => $g['confidence'], 'confidence_tier' => $g['confidence_tier'],
                'evidence' => is_string($g['evidence']) ? $g['evidence'] : json_encode($g['evidence'], JSON_UNESCAPED_UNICODE),
                'explanation' => is_string($g['explanation']) ? $g['explanation'] : json_encode($g['explanation'], JSON_UNESCAPED_UNICODE),
                'as_of_date' => $asOf, 'first_detected_at' => $now, 'last_detected_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        // Re-found open/shadow decisions keep their status, first-seen time and any
        // review verdict; their figures are refreshed.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('assortment_gaps')->upsert(
                $chunk,
                ['tenant_id', 'store_id', 'sku', 'type'],
                ['product_id', 'peer_group', 'value_low', 'value_mid', 'value_high', 'confidence', 'confidence_tier',
                    'evidence', 'explanation', 'as_of_date', 'last_detected_at', 'updated_at'],
            );
        }
    }
}
