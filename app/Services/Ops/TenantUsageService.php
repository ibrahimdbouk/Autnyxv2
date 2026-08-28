<?php

namespace App\Services\Ops;

use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\Import;
use App\Models\Investigation;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesTransaction;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Recovery\AnomalyRecoveryService;
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
     * Full profile for one tenant — the /ops deep-dive.
     *
     * @return array<string,mixed>
     */
    public function forTenant(Tenant $tenant): array
    {
        $id = $tenant->id;

        $recovery = app(AnomalyRecoveryService::class)->summary($id);

        $anomaliesBySeverity = Anomaly::where('tenant_id', $id)->active()
            ->selectRaw('severity, COUNT(*) AS c')->groupBy('severity')->pluck('c', 'severity')->all();

        $sso = $tenant->ssoConnection;

        return [
            'tenant'   => $tenant,
            'currency' => $tenant->currencyCode(),
            'volumes'  => [
                'users'           => User::where('tenant_id', $id)->count(),
                'admins'          => User::where('tenant_id', $id)->where('is_tenant_admin', true)->count(),
                'stores'          => Store::where('tenant_id', $id)->count(),
                'products'        => Product::where('tenant_id', $id)->count(),
                'sales_rows'      => SalesTransaction::where('tenant_id', $id)->count(),
                'inventory_rows'  => InventoryLevel::where('tenant_id', $id)->count(),
                'purchase_orders' => PurchaseOrder::where('tenant_id', $id)->count(),
                'suppliers'       => Supplier::where('tenant_id', $id)->count(),
            ],
            'anomalies' => [
                'active'   => Anomaly::where('tenant_id', $id)->active()->count(),
                'resolved' => Anomaly::where('tenant_id', $id)->where('lifecycle_state', Anomaly::LIFECYCLE_RESOLVED)->count(),
                'high'     => (int) ($anomaliesBySeverity['high'] ?? 0),
                'medium'   => (int) ($anomaliesBySeverity['medium'] ?? 0),
                'low'      => (int) ($anomaliesBySeverity['low'] ?? 0),
            ],
            'investigations' => [
                'total' => Investigation::where('tenant_id', $id)->count(),
                'open'  => Investigation::where('tenant_id', $id)
                    ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])->count(),
            ],
            'recovery' => [
                'observed_total' => (float) $recovery['observed_total'],
                'observed_mtd'   => (float) $recovery['observed_mtd'],
                'active_at_risk' => (float) $recovery['active_at_risk'],
                'clear_rate'     => $recovery['clear_rate'],
            ],
            'sso' => [
                'enabled' => (bool) ($sso?->enabled),
                'label'   => $sso?->label,
                'issuer'  => $sso?->issuer,
            ],
            'recent_imports' => Import::where('tenant_id', $id)
                ->orderByDesc('created_at')->limit(5)
                ->get(['id', 'data_type', 'status', 'total_rows', 'created_at']),
            'recent_activity' => AuditLog::where('tenant_id', $id)
                ->orderByDesc('created_at')->limit(15)
                ->get(['event_type', 'description', 'user_id', 'created_at']),
            'users' => User::where('tenant_id', $id)
                ->orderByDesc('is_tenant_admin')->orderBy('name')
                ->get(['id', 'name', 'email', 'is_tenant_admin', 'is_super_admin', 'last_login_at']),
        ];
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
