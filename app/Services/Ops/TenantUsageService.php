<?php

namespace App\Services\Ops;

use App\Models\Anomaly;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 2a — cross-tenant usage metrics for the super-admin control plane.
 *
 * Every figure is a grouped aggregate keyed by tenant_id (one query per table,
 * not per tenant), then assembled per row — cheap enough for an ops dashboard,
 * and the page caches the result besides.
 */
class TenantUsageService
{
    /** Platform-wide headline tiles. */
    public function overview(): array
    {
        $tenants   = Tenant::count();
        $active    = Tenant::where('status', Tenant::STATUS_ACTIVE)->count();
        $suspended = Tenant::where('status', Tenant::STATUS_SUSPENDED)->count();
        $users     = User::count();

        $anomaliesActive = Anomaly::query()->active()->count();

        // Deliberately NO cross-tenant recovery total here — tenants carry
        // different currencies, so summing value_at_open platform-wide would mix
        // them. Recovery is shown per tenant (in that tenant's currency) instead.
        return [
            'tenants'          => $tenants,
            'active'           => $active,
            'suspended'        => $suspended,
            'users'            => $users,
            'anomalies_active' => $anomaliesActive,
        ];
    }

    /**
     * Per-tenant usage rows, newest tenant first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function perTenant(): array
    {
        $tenants = Tenant::orderByDesc('created_at')->get();

        $users     = $this->countBy(User::query());
        $products  = $this->countBy(Product::query());
        $sales     = $this->countBy(SalesTransaction::query());
        $inventory = $this->countBy(InventoryLevel::query());

        $activeAnomalies = $this->mapBy(
            Anomaly::query()->active()
                ->selectRaw('tenant_id, COUNT(*) AS c')->groupBy('tenant_id')
        );

        $recovery = $this->mapBy(
            Anomaly::query()
                ->where('lifecycle_state', Anomaly::LIFECYCLE_RESOLVED)
                ->where(fn ($w) => $w->where('backfilled', false)->orWhereNull('backfilled'))
                ->selectRaw('tenant_id, COALESCE(SUM(value_at_open),0) AS c')->groupBy('tenant_id')
        );

        $lastIngestion = $this->mapBy(
            DB::table('ingestion_runs')
                ->selectRaw('tenant_id, MAX(created_at) AS c')->groupBy('tenant_id'),
            'c'
        );

        return $tenants->map(fn (Tenant $t) => [
            'id'                 => $t->id,
            'name'               => $t->name,
            'slug'               => $t->slug,
            'plan'               => $t->planLabel(),
            'status'             => $t->status ?? Tenant::STATUS_ACTIVE,
            'currency'           => $t->currencyCode(),
            'created_at'         => $t->created_at,
            'users'              => (int) ($users[$t->id] ?? 0),
            'products'           => (int) ($products[$t->id] ?? 0),
            'sales_rows'         => (int) ($sales[$t->id] ?? 0),
            'inventory_rows'     => (int) ($inventory[$t->id] ?? 0),
            'anomalies_active'   => (int) ($activeAnomalies[$t->id] ?? 0),
            'observed_recovery'  => (float) ($recovery[$t->id] ?? 0),
            'last_ingestion_at'  => $lastIngestion[$t->id] ?? null,
        ])->all();
    }

    /**
     * COUNT(*) grouped by tenant_id → [tenant_id => count].
     *
     * @return array<int,int>
     */
    private function countBy($query): array
    {
        return $this->mapBy(
            $query->selectRaw('tenant_id, COUNT(*) AS c')->groupBy('tenant_id')
        );
    }

    /** @return array<int,mixed> tenant_id => value */
    private function mapBy($query, string $col = 'c'): array
    {
        return collect($query->get())
            ->mapWithKeys(fn ($r) => [(int) $r->tenant_id => $r->{$col}])
            ->all();
    }
}
