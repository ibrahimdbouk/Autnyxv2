<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Filament\Resources\AssortmentDecisionResource;
use App\Models\AssortmentGap;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Assortment\PeerGroups;
use App\Services\Org\OrgDirectory;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * Store range profile — one store against the stores it is compared with:
 * how many products it carries, how much of the range most of its peers carry
 * it has, and its open decisions.
 */
class AssortmentStoreProfile extends Page
{
    use GatesAssortmentScreen;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const SCREEN_KEY = 'assortment_stores';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $navigationLabel = 'Store Range Profiles';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'assortment-stores';

    protected string $view = 'filament.pages.assortment-store-profile';

    #[Url(as: 'store')]
    public ?int $store = null;

    public function getTitle(): string
    {
        return 'Store range profiles';
    }

    /** @return array<int,string> stores this person may see */
    public function storeOptions(): array
    {
        $tenantId = Filament::getTenant()?->id;
        $scope = auth()->user() ? app(OrgDirectory::class)->storeScope(auth()->user()) : null;

        return Store::where('tenant_id', $tenantId)
            ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    protected function getViewData(): array
    {
        $options = $this->storeOptions();
        if ($this->store === null || ! isset($options[$this->store])) {
            $this->store = array_key_first($options);
        }
        $tenant = Filament::getTenant();

        return [
            'options'  => $options,
            'profile'  => $this->store ? $this->profile((int) $tenant->id, (int) $this->store) : null,
            'currency' => $tenant->currencyCode(),
        ];
    }

    /** @return array<string,mixed> */
    private function profile(int $tenantId, int $storeId): array
    {
        $groups = app(PeerGroups::class)->build($tenantId);
        $key    = $groups['assignment'][$storeId] ?? null;
        $group  = $key ? $groups['groups'][$key] : null;
        $peers  = $group ? array_values(array_diff($group['members'], [$storeId])) : [];

        $carried = fn (array $ids) => DB::table('assortment_store_ranges')->where('tenant_id', $tenantId)
            ->whereIn('store_id', $ids)->where('carried', true)->groupBy('store_id')
            ->selectRaw('store_id, COUNT(*) AS n')->pluck('n', 'store_id');
        $own      = (int) ($carried([$storeId])[$storeId] ?? 0);
        $peerN    = $peers ? $carried($peers)->values()->sort()->values() : collect();
        $peerMid  = $peerN->isEmpty() ? null : (int) $peerN->median();

        // Of the products most of its peers carry, how many does this store carry?
        $core = $key ? DB::table('assortment_benchmarks')->where('tenant_id', $tenantId)->where('peer_group', $key)
            ->where('carried_share', '>=', 0.5)->pluck('sku') : collect();
        $coreHas = $core->isEmpty() ? 0 : DB::table('assortment_store_ranges')->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)->where('carried', true)->whereIn('sku', $core)->count();

        $open = AssortmentDecisionResource::getEloquentQuery()->where('assortment_gaps.store_id', $storeId)
            ->where('status', AssortmentGap::STATUS_OPEN)
            ->orderByRaw('value_mid * confidence DESC')->get();

        return [
            'store'      => Store::find($storeId),
            'group'      => $group,
            'peers'      => Store::whereIn('id', $peers)->orderBy('name')->pluck('name')->all(),
            'carried'    => $own,
            'peerMedian' => $peerMid,
            'core'       => $core->count(),
            'coreHas'    => $coreHas,
            'open'       => $open->groupBy('type'),
            'openValue'  => (float) $open->sum('value_mid'),
            'listUrl'    => AssortmentDecisionResource::getUrl('index', ['filters' => ['store_id' => ['value' => $storeId]]]),
        ];
    }
}
