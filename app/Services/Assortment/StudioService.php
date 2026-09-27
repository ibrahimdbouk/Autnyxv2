<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGap;
use App\Models\AssortmentPlan;
use App\Models\AssortmentScenario;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Intelligence\Substitution\DemandTransferService;
use App\Platform\Intelligence\Substitution\TransferEstimator;
use App\Services\Org\OrgDirectory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * v1.5 Phase 3 — the Assortment Decision Studio: one store × category, today's
 * range against the one being tried, recalculated as the person works.
 *
 *   context   everything the shelf needs, prepared once and cached: the range
 *             today with its sales, margin and stock (Observed), the engine's
 *             decisions for it (Estimated), products similar stores carry that
 *             the engine did not propose (offered as Simulated), the observed
 *             transfer pairs, the peer figures and the category's strategy.
 *   simulate  the person's changes in the order made, each valued on the shelf
 *             as it stands after the ones before (RangeOptimizer::evaluate) —
 *             the same figures the nightly plans use. Labelled Simulated.
 *   presets   the optimiser's plan under another objective or limit: Sales
 *             max, Margin max, Balanced, Lean range, High availability, and
 *             Recommended (the category's own strategy).
 *   product   why keep or remove it, who substitutes it, what happens if it
 *             goes, the space it uses, what similar stores do, the confidence,
 *             and what happened after similar past decisions.
 *   scenario  inputs and the result as simulated, saved — compared as saved.
 *   plan      the tried range made into the shelf's range plan (source
 *             studio): then reviewed, accepted as one reset task and measured
 *             like any other plan.
 *
 * Nothing here writes to the range, the ERP or the engine's rules.
 */
class StudioService
{
    public const CACHE_MINUTES = 30;

    public const OTHERS = 25;   // products similar stores carry, offered beyond the engine's adds

    public const USER_CONFIDENCE = 0.3;

    public const PRESETS = [
        'recommended'  => ['label' => 'Recommended',       'objective' => null,              'room' => null, 'about' => "The category's own role and objective"],
        'sales'        => ['label' => 'Sales max',         'objective' => 'sales',           'room' => null, 'about' => 'Most category sales'],
        'margin'       => ['label' => 'Margin max',        'objective' => 'margin',          'room' => null, 'about' => 'Most gross margin'],
        'balanced'     => ['label' => 'Balanced',          'objective' => 'balanced',        'room' => null, 'about' => 'Sales, margin, availability and stock together'],
        'lean'         => ['label' => 'Lean range',        'objective' => 'working_capital', 'room' => 0.0,  'about' => 'No more products than today; less stock'],
        'availability' => ['label' => 'High availability', 'objective' => 'availability',    'room' => null, 'about' => 'Sales back on the shelf first'],
    ];

    public function __construct(
        private RangePlanner $planner,
        private RangeOptimizer $optimiser,
        private DemandTransferService $transfers,
        private TransferEstimator $estimator,
        private PeerGroups $peerGroups,
    ) {}

    // ── Where the person can work ─────────────────────────────────────────────

    /** @return array<int,string> store id => name, within the person's store scope */
    public function stores(Tenant $tenant, User $user): array
    {
        $q = Store::where('tenant_id', $tenant->id)->orderBy('name');
        $scope = app(OrgDirectory::class)->storeScope($user);
        if ($scope !== null) {
            $q->whereIn('id', $scope);
        }

        return $q->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    /** @return array<int,array{category:string, skus:int, open:int}> the store's categories, those with decisions first */
    public function categories(Tenant $tenant, int $storeId): array
    {
        $rows = DB::select(
            "SELECT TRIM(p.category) AS cat, COUNT(*) AS skus
               FROM assortment_store_ranges r JOIN products p ON p.tenant_id = r.tenant_id AND p.sku = r.sku
              WHERE r.tenant_id = ? AND r.store_id = ? AND r.carried AND COALESCE(TRIM(p.category), '') <> ''
           GROUP BY TRIM(p.category)", [$tenant->id, $storeId]);
        $open = [];
        foreach (DB::select(
            "SELECT TRIM(p.category) AS cat, COUNT(*) AS n
               FROM assortment_gaps g JOIN products p ON p.tenant_id = g.tenant_id AND p.sku = g.sku
              WHERE g.tenant_id = ? AND g.store_id = ? AND g.status IN (?, ?)
           GROUP BY TRIM(p.category)", [$tenant->id, $storeId, AssortmentGap::STATUS_OPEN, AssortmentGap::STATUS_SHADOW]) as $r) {
            $open[$r->cat] = (int) $r->n;
        }
        $out = array_map(fn ($r) => ['category' => (string) $r->cat, 'skus' => (int) $r->skus, 'open' => $open[$r->cat] ?? 0], $rows);
        usort($out, fn ($a, $b) => [$b['open'] > 0, $a['category']] <=> [$a['open'] > 0, $b['category']]);

        return $out;
    }

    // ── The shelf, prepared once ──────────────────────────────────────────────

    public function context(Tenant $tenant, int $storeId, string $category): array
    {
        $run = DB::table('assortment_runs')->where('tenant_id', $tenant->id)->where('status', 'success')
            ->orderByDesc('id')->first(['id', 'as_of_date']);
        // Rebuilt after a run, a strategy change, a must-stock change or a plan made for the shelf.
        $key = 'studio:v1:' . $tenant->id . ':' . $storeId . ':' . md5($category) . ':' . ($run->id ?? 0) . ':' . md5(json_encode([
            CategoryStrategy::for($tenant, $category),
            DB::table('assortment_must_stock')->where('tenant_id', $tenant->id)->selectRaw('COUNT(*) AS n, MAX(updated_at) AS t')->first(),
            AssortmentPlan::where('tenant_id', $tenant->id)->where('store_id', $storeId)->where('category', $category)->max('updated_at'),
        ]));

        return Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), fn () => $this->build($tenant, $storeId, $category, $run, $key));
    }

    private function build(Tenant $tenant, int $storeId, string $category, ?object $run, string $key): array
    {
        $tenantId = (int) $tenant->id;
        $asOf = (string) ($run->as_of_date ?? now()->toDateString());
        $products = ProductFacts::load($tenantId, $category);

        $shelf = $this->planner->shelvesFor($tenantId, $storeId, [$category], $asOf, $products)[$category]
            ?? ['skus' => [], 'baseline' => ['sales' => 0.0, 'margin' => 0.0, 'stock' => 0.0, 'count' => 0]];
        $shelf = $this->planner->protect($shelf, $storeId, $this->planner->protections($tenantId));

        $groups = $this->peerGroups->build($tenantId);
        $pool = (string) ($groups['assignment'][$storeId] ?? '');
        $group = $groups['groups'][$pool] ?? null;

        // The engine's decisions for this shelf (open, or in review).
        $engine = [];
        $gaps = AssortmentGap::where('tenant_id', $tenantId)->where('store_id', $storeId)
            ->whereIn('status', [AssortmentGap::STATUS_OPEN, AssortmentGap::STATUS_SHADOW])
            ->whereIn('sku', array_keys($products))->get();
        foreach ($gaps as $g) {
            $c = $this->planner->candidateFrom($g, $products);
            if ($c === null) {
                continue;
            }
            $c['headline'] = $g->headline();
            $c['explanation'] = array_values(array_filter((array) (($g->explanation ?? [])['evidence'] ?? []), 'is_string'));
            $c['gap_status'] = $g->status;
            $engine[$c['sku']] = $c;
            $pool = $pool !== '' ? $pool : (string) $g->peer_group;
        }
        // A product that keeps running out is fixed, not delisted, unless the person says otherwise.
        foreach ($engine as $sku => $c) {
            if ($c['kind'] === AssortmentPlan::RECOVER && isset($shelf['skus'][$sku]) && empty($shelf['skus'][$sku]['protected'])) {
                $shelf['skus'][$sku]['protected'] = 'Keeps running out: fix the stock rather than delist it';
            }
        }

        // What similar stores do, per product.
        $bench = [];
        if ($pool !== '') {
            foreach (DB::table('assortment_benchmarks')->where('tenant_id', $tenantId)->where('peer_group', $pool)
                ->whereIn('sku', array_keys($products))
                ->get(['sku', 'group_size', 'carrying', 'qualifying', 'share_index_median', 'revenue_per_day_median', 'availability_median']) as $b) {
                $bench[(string) $b->sku] = [
                    'group_size' => (int) $b->group_size, 'carrying' => (int) $b->carrying, 'qualifying' => (int) $b->qualifying,
                    'share' => $b->share_index_median !== null ? (float) $b->share_index_median : null,
                    'rev_day' => $b->revenue_per_day_median !== null ? (float) $b->revenue_per_day_median : null,
                    'availability' => $b->availability_median !== null ? (float) $b->availability_median : null,
                ];
            }
        }

        // Products similar stores carry that this shelf lacks and the engine did not propose — offered, labelled Simulated.
        $catPerDay = (float) $shelf['baseline']['sales'] / 365;
        $others = [];
        foreach ($bench as $sku => $b) {
            if (isset($shelf['skus'][$sku]) || isset($engine[$sku]) || $b['carrying'] < 1 || $b['share'] === null || $catPerDay <= 0) {
                continue;
            }
            $p = $products[$sku];
            $gross = $b['share'] * $catPerDay * 365 * ($b['availability'] ?? 1.0);
            if ($gross <= 0) {
                continue;
            }
            $others[$sku] = [
                'kind' => AssortmentPlan::ADD, 'gap_id' => null, 'sku' => (string) $sku, 'name' => (string) ($p['name'] ?: $sku),
                'tier' => AssortmentGap::TIER_SPECULATIVE, 'confidence' => self::USER_CONFIDENCE,
                'gross_sales' => round($gross, 2), 'current_sales' => 0.0, 'margin_rate' => $this->rate($p), 'stock_value' => 0.0,
                'units_per_day' => $p['price'] > 0 ? $gross / 365 / $p['price'] : 0.0, 'cost' => (float) $p['cost'], 'source' => 'user',
            ];
        }
        uasort($others, fn ($a, $b) => [$b['gross_sales'], $a['sku']] <=> [$a['gross_sales'], $b['sku']]);
        $others = array_slice($others, 0, self::OTHERS, true);

        $skus = array_values(array_unique(array_merge(array_map('strval', array_keys($shelf['skus'])), array_keys($engine), array_keys($others))));
        $plan = AssortmentPlan::where('tenant_id', $tenantId)->where('store_id', $storeId)->where('category', $category)
            ->whereIn('status', [AssortmentPlan::STATUS_DRAFT, AssortmentPlan::STATUS_PROPOSED, AssortmentPlan::STATUS_ACCEPTED, AssortmentPlan::STATUS_IN_PROGRESS])
            ->latest('id')->first(['id', 'status', 'source']);

        return [
            'key'        => $key,
            'tenant_id'  => $tenantId,
            'store_id'   => $storeId,
            'store_name' => (string) (Store::where('tenant_id', $tenantId)->whereKey($storeId)->value('name') ?? 'store #' . $storeId),
            'category'   => $category,
            'peer_group' => $pool,
            'peer_label' => $group['label'] ?? null,
            'peer_size'  => $group ? count($group['members']) : null,
            'as_of'      => $asOf,
            'run_id'     => (int) ($run->id ?? 0),
            'strategy'   => CategoryStrategy::for($tenant, $category),
            'shelf'      => $shelf,
            'engine'     => $engine,
            'others'     => $others,
            'products'   => $products,
            'observed'   => $pool !== '' ? $this->transfers->observed($tenantId, $pool, $skus) : [],
            'bench'      => $bench,
            'plan'       => $plan ? ['id' => (int) $plan->id, 'status' => $plan->status, 'source' => $plan->source] : null,
        ];
    }

    // ── Trying a range ────────────────────────────────────────────────────────

    /** The inputs a fresh workspace starts from: today's range, the category's strategy. */
    public static function blank(): array
    {
        return ['objective' => null, 'room' => null, 'max_size' => null, 'min_size' => null, 'picks' => [], 'protect' => [], 'unprotect' => [], 'skip' => []];
    }

    public function strategy(array $ctx, array $inputs): array
    {
        $s = $ctx['strategy'];
        if (! empty($inputs['objective']) && isset(CategoryStrategy::OBJECTIVES[$inputs['objective']])) {
            $s['objective'] = $inputs['objective'];
        }
        if (($inputs['room'] ?? null) !== null && $inputs['room'] !== '') {
            $s['room'] = max(0.0, min(0.5, (float) $inputs['room']));
        }
        if (! empty($inputs['max_size'])) {
            $s['max_size'] = max(1, (int) $inputs['max_size']);
        }
        $s['min_size'] = ! empty($inputs['min_size']) ? max(1, (int) $inputs['min_size']) : null;

        return $s;
    }

    /** The shelf with the person's protections applied. */
    public function shelf(array $ctx, array $inputs): array
    {
        $shelf = $ctx['shelf'];
        foreach ((array) ($inputs['protect'] ?? []) as $sku) {
            if (isset($shelf['skus'][$sku]) && empty($shelf['skus'][$sku]['protected'])) {
                $shelf['skus'][$sku]['protected'] = 'Protected in this scenario';
            }
        }
        foreach ((array) ($inputs['unprotect'] ?? []) as $sku) {
            if (isset($shelf['skus'][$sku])) {
                $shelf['skus'][$sku]['protected'] = null;
                $shelf['skus'][$sku]['unprotected'] = true;
            }
        }

        return $shelf;
    }

    public function simulate(array $ctx, array $inputs): array
    {
        $inputs += self::blank();
        $shelf = $this->shelf($ctx, $inputs);
        $moves = [];
        foreach ((array) $inputs['picks'] as $pick) {
            $c = $this->move($ctx, $shelf, (string) ($pick['kind'] ?? ''), (string) ($pick['sku'] ?? ''));
            if ($c !== null) {
                $moves[] = $c;
            }
        }
        $skip = array_flip(array_map('strval', (array) $inputs['skip']));
        $recovers = array_values(array_filter($ctx['engine'], fn ($c) => $c['kind'] === AssortmentPlan::RECOVER && ! isset($skip[$c['sku']])));

        $result = $this->optimiser->evaluate($shelf, $moves, $recovers, $this->strategy($ctx, $inputs), $this->transferFn($ctx));
        $result['summary'] = $this->optimiser->summary($result);
        $result['inputs'] = $inputs;

        return $result;
    }

    /** The optimiser's plan under a preset objective or limit; loadable into the workspace. */
    public function preset(array $ctx, string $key): array
    {
        $p = self::PRESETS[$key] ?? self::PRESETS['recommended'];

        return Cache::remember($ctx['key'] . ':preset:' . $key, now()->addMinutes(self::CACHE_MINUTES), function () use ($ctx, $key, $p) {
            $inputs = ['objective' => $p['objective'], 'room' => $p['room']] + self::blank();
            if ($key === 'lean') {
                $inputs['max_size'] = max(1, (int) $ctx['shelf']['baseline']['count']);
            }
            $strategy = $this->strategy($ctx, $inputs);
            $result = $this->optimiser->optimise($ctx['shelf'], array_values($ctx['engine']), $strategy, $this->transferFn($ctx));
            $picks = [];
            foreach ($result['changes'] as $c) {
                match ($c['kind']) {
                    AssortmentPlan::ADD    => $picks[] = ['kind' => AssortmentPlan::ADD, 'sku' => $c['sku']],
                    AssortmentPlan::DELIST => $picks[] = ['kind' => AssortmentPlan::DELIST, 'sku' => $c['sku']],
                    AssortmentPlan::SWAP   => array_push($picks, ['kind' => AssortmentPlan::ADD, 'sku' => $c['sku']], ['kind' => AssortmentPlan::DELIST, 'sku' => $c['out_sku']]),
                    default                => null,
                };
            }
            $inputs['picks'] = $picks;
            $result['summary'] = $this->optimiser->summary($result);
            $result['inputs'] = $inputs;
            $result['preset'] = $key;

            return $result;
        });
    }

    /** One pick as an optimiser candidate: the engine's figures when it has a decision, else the shelf's (or similar stores'). */
    private function move(array $ctx, array $shelf, string $kind, string $sku): ?array
    {
        $engine = $ctx['engine'][$sku] ?? null;
        if ($kind === AssortmentPlan::ADD) {
            return ($engine && $engine['kind'] === AssortmentPlan::ADD) ? $engine : ($ctx['others'][$sku] ?? null);
        }
        if ($kind !== AssortmentPlan::DELIST || ! isset($shelf['skus'][$sku])) {
            return null;
        }
        if ($engine && $engine['kind'] === AssortmentPlan::DELIST) {
            return $engine;
        }
        $p = $shelf['skus'][$sku];

        return [
            'kind' => AssortmentPlan::DELIST, 'gap_id' => null, 'sku' => $sku, 'name' => (string) ($p['name'] ?? $sku),
            'tier' => AssortmentGap::TIER_SPECULATIVE, 'confidence' => self::USER_CONFIDENCE,
            'gross_sales' => 0.0, 'current_sales' => (float) $p['sales'], 'margin_rate' => $p['margin_rate'], 'stock_value' => (float) $p['stock'],
            'units_per_day' => 0.0, 'cost' => (float) ($ctx['products'][$sku]['cost'] ?? 0), 'source' => 'user',
        ];
    }

    private function transferFn(array $ctx): callable
    {
        $products = $ctx['products'];
        $observed = $ctx['observed'];

        return fn (string $sku, array $onShelf) => $this->estimator->estimate($sku, $onShelf, $products, $observed[$sku] ?? []);
    }

    // ── One product ───────────────────────────────────────────────────────────

    public function product(array $ctx, array $result, string $sku): ?array
    {
        $facts = $ctx['products'][$sku] ?? null;
        if ($facts === null) {
            return null;
        }
        $shelfNow = $result['shelf'] ?? array_keys($ctx['shelf']['skus']);
        $onToday = isset($ctx['shelf']['skus'][$sku]);
        $onNow = in_array($sku, array_map('strval', $shelfNow), true);
        $row = $ctx['shelf']['skus'][$sku] ?? null;
        $engine = $ctx['engine'][$sku] ?? null;
        $other = $ctx['others'][$sku] ?? null;
        $b = $ctx['bench'][$sku] ?? null;
        $t = $this->estimator->estimate($sku, array_values(array_diff(array_map('strval', $shelfNow), [$sku])), $ctx['products'], $ctx['observed'][$sku] ?? []);

        $base = $ctx['shelf']['baseline'];
        $n = max(1, (int) $base['count']);
        $rate = $row['margin_rate'] ?? $this->rate($facts);
        $avgPerSlot = ((float) $base['margin'] > 0 ? (float) $base['margin'] : (float) $base['sales']) / $n;
        $sales = $row ? (float) $row['sales'] : (float) ($engine['gross_sales'] ?? $other['gross_sales'] ?? 0);
        $perSlot = $sales * ((float) $base['margin'] > 0 ? ($rate ?? 0) : 1.0);

        // What happened after similar decisions in this category.
        $type = $onToday ? AssortmentGap::TYPE_DELIST : AssortmentGap::TYPE_ADD;
        $past = AssortmentGap::where('tenant_id', $ctx['tenant_id'])->where('type', $type)->whereNotNull('measured_at')
            ->whereIn('sku', array_keys($ctx['products']))->get(['measurement'])
            ->countBy(fn ($g) => (string) (($g->measurement ?? [])['verdict'] ?? 'unknown'))->all();

        return [
            'sku' => $sku, 'name' => (string) ($facts['name'] ?: $sku), 'facts' => $facts,
            'on_today' => $onToday, 'on_now' => $onNow,
            'sales' => $row ? (float) $row['sales'] : null, 'margin_rate' => $rate, 'stock' => $row ? (float) $row['stock'] : null,
            'share_of_category' => $row && (float) $base['sales'] > 0 ? (float) $row['sales'] / (float) $base['sales'] : null,
            'protected' => $row['protected'] ?? null,
            'engine' => $engine ? ['kind' => $engine['kind'], 'headline' => $engine['headline'] ?? null, 'explanation' => $engine['explanation'] ?? [],
                'tier' => $engine['tier'], 'gap_id' => $engine['gap_id'], 'gross' => $engine['gross_sales'], 'current' => $engine['current_sales']] : null,
            'offered' => $other ? ['gross' => $other['gross_sales']] : null,
            'transfer' => $t,
            'space' => ['per_slot' => $perSlot, 'average' => $avgPerSlot, 'count' => $n],
            'peers' => $b ? ['carrying' => $b['carrying'], 'size' => $b['group_size'],
                'sales_a_year' => $b['rev_day'] !== null ? $b['rev_day'] * 365 * ($b['availability'] ?? 1.0) : null] : null,
            'peer_label' => $ctx['peer_label'],
            'past' => ['type' => $type, 'success' => $past['success'] ?? 0, 'partial' => $past['partial'] ?? 0, 'failure' => $past['failure'] ?? 0],
        ];
    }

    // ── Scenarios ─────────────────────────────────────────────────────────────

    public function save(array $ctx, User $by, array $inputs, string $name, ?string $preset = null): AssortmentScenario
    {
        $result = $preset ? $this->preset($ctx, $preset) : $this->simulate($ctx, $inputs);

        return AssortmentScenario::create([
            'tenant_id' => $ctx['tenant_id'], 'store_id' => $ctx['store_id'], 'category' => mb_substr($ctx['category'], 0, 191),
            'name' => mb_substr(trim($name) ?: 'Scenario', 0, 120), 'preset' => $preset,
            'inputs' => $result['inputs'], 'result' => $this->snapshot($result), 'as_of_date' => $ctx['as_of'], 'created_by' => $by->id,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int,AssortmentScenario> */
    public function scenarios(array $ctx, int $limit = 6)
    {
        return AssortmentScenario::with('creator:id,name')->where('tenant_id', $ctx['tenant_id'])->where('store_id', $ctx['store_id'])
            ->where('category', $ctx['category'])->latest('id')->limit($limit)->get();
    }

    private function snapshot(array $result): array
    {
        return ['summary' => $result['summary'], 'changes' => $result['changes'], 'violations' => $result['violations'] ?? [],
            'constraints' => $result['constraints'], 'version' => $result['version']];
    }

    // ── Making it the plan ────────────────────────────────────────────────────

    /** Whether this person may make the shelf's plan now (plans open, or an admin while they are in review). */
    public function canMakePlan(Tenant $tenant, User $user): bool
    {
        return TenantAssortment::plansLive($tenant) || $user->canManageUsers();
    }

    public function makePlan(Tenant $tenant, User $by, array $ctx, array $inputs, ?string $name = null): AssortmentPlan
    {
        abort_unless($this->canMakePlan($tenant, $by), 403, 'Range plans are in review; a plan can be made here once they open.');
        $result = $this->simulate($ctx, $inputs);
        abort_if(($result['violations'] ?? []) !== [], 422, 'This range breaks a limit: ' . implode(' ', array_column($result['violations'], 'why')));
        $acting = array_filter($result['changes'], fn ($c) => $c['kind'] !== AssortmentPlan::PROTECT);
        abort_if($acting === [], 422, 'Nothing is changed yet — make a change or load a scenario first.');
        $busy = AssortmentPlan::where('tenant_id', $ctx['tenant_id'])->where('store_id', $ctx['store_id'])->where('category', $ctx['category'])
            ->whereIn('status', [AssortmentPlan::STATUS_ACCEPTED, AssortmentPlan::STATUS_IN_PROGRESS])->exists();
        abort_if($busy, 422, 'A plan for this shelf is being carried out; it is measured before the next one.');

        $strategy = $this->strategy($ctx, $inputs);
        $plan = DB::transaction(function () use ($tenant, $by, $ctx, $inputs, $name, $result, $strategy) {
            $scenario = AssortmentScenario::create([
                'tenant_id' => $ctx['tenant_id'], 'store_id' => $ctx['store_id'], 'category' => mb_substr($ctx['category'], 0, 191),
                'name' => mb_substr(trim((string) $name) ?: 'Plan made in the Studio', 0, 120), 'preset' => null,
                'inputs' => $result['inputs'], 'result' => $this->snapshot($result), 'as_of_date' => $ctx['as_of'], 'created_by' => $by->id,
            ]);
            AssortmentPlan::where('tenant_id', $ctx['tenant_id'])->where('store_id', $ctx['store_id'])->where('category', $ctx['category'])
                ->whereIn('status', [AssortmentPlan::STATUS_DRAFT, AssortmentPlan::STATUS_PROPOSED])->delete();
            $keys = array_column(array_filter($result['changes'], fn ($c) => $c['kind'] !== AssortmentPlan::PROTECT), 'key');
            sort($keys);

            return AssortmentPlan::create([
                'tenant_id' => $ctx['tenant_id'], 'store_id' => $ctx['store_id'], 'category' => mb_substr($ctx['category'], 0, 191),
                'peer_group' => mb_substr($ctx['peer_group'], 0, 64) ?: null,
                'status' => TenantAssortment::plansLive($tenant) ? AssortmentPlan::STATUS_PROPOSED : AssortmentPlan::STATUS_DRAFT,
                'role' => $strategy['role'], 'objective' => $strategy['objective'], 'feasible' => true, 'infeasible_reason' => null,
                'current_count' => $result['current_count'], 'proposed_count' => $result['proposed_count'],
                'changes' => $result['changes'],
                'impact' => array_merge($result['impact'], ['margin_known' => $result['margin_known'], 'basis' => 'simulated']),
                'constraints' => $result['constraints'] + ['left_out' => [], 'inputs' => $result['inputs']],
                'value_mid' => round((float) $result['value_mid'], 2), 'confidence' => round((float) $result['confidence'], 4),
                'confidence_tier' => $result['confidence_tier'],
                'fingerprint' => hash('sha256', $ctx['store_id'] . '|' . $ctx['category'] . '|' . implode(',', $keys) . '|studio'),
                'optimizer_version' => $result['version'], 'as_of_date' => $ctx['as_of'],
                'source' => AssortmentPlan::SOURCE_STUDIO, 'scenario_id' => $scenario->id, 'created_by' => $by->id,
            ]);
        });

        try {
            AuditLog::create(['tenant_id' => $ctx['tenant_id'], 'user_id' => $by->id, 'event_type' => 'assortment.plan_studio',
                'description' => mb_substr('Range plan made in the Decision Studio: ' . $plan->headline(), 0, 250),
                'new_value' => ['plan_id' => $plan->id, 'scenario_id' => $plan->scenario_id, 'status' => $plan->status], 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
        return $plan;
    }

    private function rate(array $p): ?float
    {
        $price = (float) ($p['price'] ?? 0);
        $cost = (float) ($p['cost'] ?? 0);

        return $price > 0 && $cost > 0 && $price > $cost ? ($price - $cost) / $price : null;
    }
}
