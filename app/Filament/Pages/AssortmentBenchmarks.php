<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Models\AssortmentGap;
use App\Models\Tenant;
use App\Services\Assortment\PeerGroups;
use App\Services\Assortment\TenantAssortment;
use App\Services\Org\OrgDirectory;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * v2 — the benchmark explorer: any peer group's expected range for a
 * category, and the reasoning behind each product. Built from what the
 * group's stores carry and sell (Observed), as the engine measured it:
 *
 *   core      carried by at least 80% of the group's stores
 *   expected  carried by at least half
 *   niche     carried by fewer
 *
 * Optionally against one store: what it carries of the expected range, what
 * it lacks, and the decisions the engine made there.
 */
class AssortmentBenchmarks extends Page
{
    use GatesAssortmentScreen;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const SCREEN_KEY = 'assortment_benchmarks';

    public const CORE = 0.80;

    public const EXPECTED = 0.50;

    public const ROWS = 50;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-scale';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $navigationLabel = 'Benchmarks';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'assortment-benchmarks';

    protected string $view = 'filament.pages.assortment-benchmarks';

    #[Url(as: 'group')]
    public ?string $group = null;

    #[Url(as: 'category')]
    public ?string $category = null;

    #[Url(as: 'store')]
    public int|string|null $store = null;   // '' from the picker = no store

    public string $search = '';

    public string $show = 'expected';   // expected | core | niche | all

    public ?string $focus = null;

    public int $limit = self::ROWS;

    private ?array $groupsMemo = null;

    public function getTitle(): string
    {
        return 'Benchmarks';
    }

    private function tenantId(): int
    {
        return (int) Filament::getTenant()?->id;
    }

    /** @return array<string,array{key:string,label:string,members:array<int,int>}> peer groups that have benchmarks */
    public function groups(): array
    {
        if ($this->groupsMemo !== null) {
            return $this->groupsMemo;
        }
        $have = DB::table('assortment_benchmarks')->where('tenant_id', $this->tenantId())->distinct()->pluck('peer_group')->flip();
        $groups = array_filter(app(PeerGroups::class)->build($this->tenantId())['groups'], fn ($g) => isset($have[$g['key']]));
        $scope = auth()->user() ? app(OrgDirectory::class)->storeScope(auth()->user()) : null;
        if ($scope !== null) {
            // Someone with some stores sees the groups those stores belong to.
            $groups = array_filter($groups, fn ($g) => array_intersect($g['members'], $scope) !== []);
        }
        uasort($groups, fn ($a, $b) => [$a['label'], $a['key']] <=> [$b['label'], $b['key']]);

        return $this->groupsMemo = $groups;
    }

    /** @return array<int,array{category:string,n:int}> */
    public function categories(): array
    {
        if ($this->group === null) {
            return [];
        }

        return collect(DB::select(
            "SELECT TRIM(p.category) AS cat, COUNT(*) AS n
               FROM assortment_benchmarks b JOIN products p ON p.tenant_id = b.tenant_id AND p.sku = b.sku
              WHERE b.tenant_id = ? AND b.peer_group = ? AND COALESCE(TRIM(p.category), '') <> ''
           GROUP BY 1 ORDER BY 1", [$this->tenantId(), $this->group]))
            ->map(fn ($r) => ['category' => (string) $r->cat, 'n' => (int) $r->n])->all();
    }

    public function mount(): void
    {
        $this->store = $this->store === null || $this->store === '' ? null : (int) $this->store;
        $groups = $this->groups();
        if ($this->group === null || ! isset($groups[$this->group])) {
            $this->group = array_key_first($groups);
        }
        $this->pickCategory();
        if ($this->store !== null && ! in_array($this->store, $groups[$this->group]['members'] ?? [], true)) {
            $this->store = null;
        }
    }

    private function pickCategory(): void
    {
        $cats = array_column($this->categories(), 'category');
        if ($this->category === null || ! in_array($this->category, $cats, true)) {
            $this->category = $cats[0] ?? null;
        }
    }

    public function updatedGroup(): void
    {
        abort_unless(isset($this->groups()[$this->group]), 404);
        $this->store = null;
        $this->focus = null;
        $this->limit = self::ROWS;
        $this->pickCategory();
    }

    public function updatedCategory(): void
    {
        $this->focus = null;
        $this->limit = self::ROWS;
        $this->pickCategory();
    }

    public function updatedStore(): void
    {
        $this->store = $this->store === null || $this->store === '' ? null : (int) $this->store;
        if ($this->store !== null && ! in_array((int) $this->store, $this->groups()[$this->group]['members'] ?? [], true)) {
            $this->store = null;
        }
    }

    public function focusOn(?string $sku): void
    {
        $this->focus = $sku;
    }

    public function more(): void
    {
        $this->limit = min(2000, $this->limit + 100);
    }

    public static function band(float $carriedShare): string
    {
        return $carriedShare >= self::CORE ? 'core' : ($carriedShare >= self::EXPECTED ? 'expected' : 'niche');
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $groups = $this->groups();
        $g = $this->group ? ($groups[$this->group] ?? null) : null;
        if ($g === null || $this->category === null) {
            return ['g' => null, 'groups' => $groups];
        }
        $tenantId = $this->tenantId();
        $rows = collect(DB::select(
            "SELECT b.sku, p.name, p.subcategory, p.brand, p.pack_size, p.selling_price, b.group_size, b.carrying, b.qualifying, b.carried_share,
                    b.share_index_p25, b.share_index_median, b.share_index_p75, b.units_per_day_median, b.revenue_per_day_median, b.availability_median,
                    l.state AS lifecycle
               FROM assortment_benchmarks b
               JOIN products p ON p.tenant_id = b.tenant_id AND p.sku = b.sku
          LEFT JOIN product_lifecycles l ON l.tenant_id = b.tenant_id AND l.sku = b.sku AND l.store_id IS NULL
              WHERE b.tenant_id = ? AND b.peer_group = ? AND TRIM(p.category) = ?",
            [$tenantId, $this->group, $this->category]))
            ->map(function ($r) {
                $r->band = static::band((float) $r->carried_share);
                $r->sales_a_year = $r->revenue_per_day_median !== null ? (float) $r->revenue_per_day_median * 365 * (float) ($r->availability_median ?? 1) : null;

                return $r;
            })
            ->sortBy([fn ($a, $b) => (float) $b->carried_share <=> (float) $a->carried_share,
                fn ($a, $b) => (float) $b->share_index_median <=> (float) $a->share_index_median, fn ($a, $b) => strcmp($a->sku, $b->sku)])
            ->values();

        $counts = ['core' => 0, 'expected' => 0, 'niche' => 0];
        foreach ($rows as $r) {
            $counts[$r->band]++;
        }

        // Decisions the engine made on this category across the group (in review: admins only).
        $statuses = TenantAssortment::isLive($tenant) || auth()->user()?->canManageUsers()
            ? [AssortmentGap::STATUS_OPEN, AssortmentGap::STATUS_SHADOW] : [AssortmentGap::STATUS_OPEN];
        $decisions = AssortmentGap::where('tenant_id', $tenantId)->whereIn('store_id', $g['members'])->whereIn('status', $statuses)
            ->whereIn('sku', $rows->pluck('sku'))->get(['id', 'sku', 'store_id', 'type']);
        $byProduct = $decisions->groupBy('sku')->map(fn ($ds) => $ds->countBy('type')->all())->all();

        // Against one store.
        $here = $hereDecisions = [];
        if ($this->store !== null) {
            $here = DB::table('assortment_store_ranges')->where('tenant_id', $tenantId)->where('store_id', $this->store)->where('carried', true)
                ->whereIn('sku', $rows->pluck('sku'))->pluck('sku')->flip()->all();
            $hereDecisions = $decisions->where('store_id', $this->store)->keyBy('sku')->all();
        }
        $coverage = null;
        if ($this->store !== null) {
            $exp = $rows->whereIn('band', ['core', 'expected']);
            $coverage = ['expected' => $exp->count(), 'carried' => $exp->filter(fn ($r) => isset($here[$r->sku]))->count(),
                'core' => $rows->where('band', 'core')->count(), 'core_carried' => $rows->where('band', 'core')->filter(fn ($r) => isset($here[$r->sku]))->count(),
                'extra' => $rows->where('band', 'niche')->filter(fn ($r) => isset($here[$r->sku]))->count()];
        }

        $term = mb_strtolower(trim($this->search));
        $list = $rows->filter(fn ($r) => match ($this->show) {
            'core'  => $r->band === 'core',
            'niche' => $r->band === 'niche',
            'all'   => true,
            default => $r->band !== 'niche',
        })->filter(fn ($r) => $term === '' || str_contains(mb_strtolower($r->name . ' ' . $r->sku), $term))->values();

        $focus = $this->focus ? $rows->firstWhere('sku', $this->focus) : null;
        $stores = DB::table('stores')->where('tenant_id', $tenantId)->whereIn('id', $g['members'])->orderBy('name')->pluck('name', 'id')->all();
        $scope = auth()->user() ? app(OrgDirectory::class)->storeScope(auth()->user()) : null;
        if ($scope !== null) {
            $stores = array_intersect_key($stores, array_flip($scope));
        }

        return [
            'g'           => $g,
            'groups'      => $groups,
            'categories'  => $this->categories(),
            'stores'      => $stores,
            'rows'        => $list->take($this->limit),
            'total'       => $list->count(),
            'counts'      => $counts,
            'byProduct'   => $byProduct,
            'here'        => $here,
            'hereDecisions' => $hereDecisions,
            'coverage'    => $coverage,
            'focusRow'    => $focus,
            'focusDecisions' => $focus ? $decisions->where('sku', $focus->sku)->count() : 0,
            'currency'    => $tenant?->currencyCode(),
            'decisionUrl' => fn ($id) => AssortmentDecision::getUrl(['decision' => $id]),
            'storeName'   => $this->store ? ($stores[$this->store] ?? null) : null,
        ];
    }
}
