<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGap;
use App\Models\AssortmentRun;
use App\Models\DataContract;
use App\Models\Tenant;
use App\Platform\Intelligence\Availability\AvailabilityService;
use App\Platform\Trust\DataConfidence;
use Illuminate\Support\Facades\DB;

/**
 * Assortment Intelligence — one tenant's run, end to end:
 *
 *   range model (A1) → peer groups → per group: benchmark (A2) → decisions (A3)
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

        $range    = $this->ranges->rebuild($tenantId, $asOf);
        $peers    = $this->peerGroups->build($tenantId);
        $products = $this->products($tenantId);
        [$factor, $reasons] = $this->dataQuality($tenantId);
        $allowDelists = $range['history_days'] >= (int) config('assortment.delist_min_history_days', 182);

        $engine = (new GapEngine($products))->withContext(
            mustStock: $this->mustStock($tenantId),
            stockValue: $this->stockValue($tenantId),
            storeNames: DB::table('stores')->where('tenant_id', $tenantId)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all(),
            qualityFactor: $factor,
            qualityReasons: $reasons,
            allowDelists: $allowDelists,
            currency: $tenant->currencyCode(),
        );

        DB::table('assortment_benchmarks')->where('tenant_id', $tenantId)->delete();

        $counts = ['add' => 0, 'delist' => 0, 'stockout_hidden' => 0, 'skipped_no_category_sales' => 0, 'skipped_speculative_delist' => 0];
        $benchmarks = 0;
        $groupsOut = [];
        foreach ($peers['groups'] as $key => $group) {
            $targets = array_keys(array_filter($peers['assignment'], fn ($k) => $k === $key));
            if ($targets === []) {
                continue;
            }
            $data  = (new GroupData($tenantId, $group['members'], $asOf, $products))->load($this->availability);
            $bench = $engine->benchmark($data);
            $benchmarks += $this->saveBenchmarks($tenantId, $group, $bench, $asOf, $data->windowDays);

            $result = $engine->evaluate($data, $targets, $bench, $group);
            foreach ($result['counts'] as $k => $n) {
                $counts[$k] += $n;
            }
            $this->saveGaps($tenantId, $result['gaps'], $asOf, $started);
            $groupsOut[] = ['key' => $key, 'label' => $group['label'], 'basis' => $group['basis'], 'stores' => count($group['members']), 'judged' => count($targets)];
        }

        // Decisions not found again this run are gone (accepted / rejected ones are kept).
        $removed = AssortmentGap::query()->where('tenant_id', $tenantId)
            ->whereIn('status', [AssortmentGap::STATUS_SHADOW, AssortmentGap::STATUS_OPEN])
            ->where(fn ($q) => $q->whereNull('last_detected_at')->orWhere('last_detected_at', '<', $started))
            ->delete();

        $coverage = $this->availability->coverage($tenantId);

        return [
            'as_of'          => $asOf,
            'shadow'         => (bool) config('assortment.shadow', true),
            'range'          => $range,
            'stock_history'  => $coverage,
            'peer_groups'    => $groupsOut,
            'stores_unjudged' => count($peers['unjudged']),
            'benchmarks'     => $benchmarks,
            'decisions'      => $counts,
            'removed'        => $removed,
            'delists_allowed' => $allowDelists,
            'data_quality'   => ['factor' => $factor, 'reasons' => $reasons],
        ];
    }

    /** @return array<string,array<string,mixed>> sku => facts */
    private function products(int $tenantId): array
    {
        $out = [];
        foreach (DB::table('products')->where('tenant_id', $tenantId)
            ->get(['id', 'sku', 'name', 'category', 'subcategory', 'selling_price', 'unit_cost', 'status', 'season']) as $p) {
            $cat = trim((string) $p->category);
            $sub = trim((string) $p->subcategory);
            $out[(string) $p->sku] = [
                'id'          => (int) $p->id,
                'name'        => (string) ($p->name ?? ''),
                'category'    => $cat !== '' ? $cat : null,
                'subcategory' => $sub !== '' ? $sub : null,
                'price'       => (float) ($p->selling_price ?? 0),
                'cost'        => (float) ($p->unit_cost ?? 0),
                'status'      => $p->status,
                'season'      => $p->season,
            ];
        }

        return $out;
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

    private function saveGaps(int $tenantId, array $gaps, string $asOf, \DateTimeInterface $started): void
    {
        $now    = now();
        $status = config('assortment.shadow', true) ? AssortmentGap::STATUS_SHADOW : AssortmentGap::STATUS_OPEN;
        $rows   = [];
        foreach ($gaps as $g) {
            $rows[] = [
                'tenant_id' => $tenantId, 'store_id' => $g['store_id'], 'sku' => $g['sku'], 'product_id' => $g['product_id'],
                'type' => $g['type'], 'status' => $status, 'peer_group' => $g['peer_group'],
                'value_low' => $g['value_low'], 'value_mid' => $g['value_mid'], 'value_high' => $g['value_high'],
                'confidence' => $g['confidence'], 'confidence_tier' => $g['confidence_tier'],
                'evidence' => json_encode($g['evidence'], JSON_UNESCAPED_UNICODE),
                'explanation' => json_encode($g['explanation'], JSON_UNESCAPED_UNICODE),
                'as_of_date' => $asOf, 'first_detected_at' => $now, 'last_detected_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        // Re-found decisions keep their status and first-seen time; everything else is refreshed.
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
