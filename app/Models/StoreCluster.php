<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * A store peer group produced by a clustering strategy
 * (Platform\Intelligence\Clustering). Derived data — rebuilt nightly.
 */
class StoreCluster extends Model
{
    /** Default clustering objective — the operational grouping shown everywhere today. */
    const OBJECTIVE_GENERAL = 'general';

    protected $fillable = [
        'tenant_id',
        'cluster_set_id',
        'method',
        'objective',
        'key',
        'label',
        'params',
    ];

    protected $casts = [
        'params' => 'array',
    ];

    /** Per-instance cache so the several metric columns compute one query, not many. */
    protected ?array $metricsMemo = null;

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function clusterSet(): BelongsTo
    {
        return $this->belongsTo(ClusterSet::class);
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(
            Store::class,
            'store_cluster_members',
            'store_cluster_id',
            'store_id',
        );
    }

    /**
     * Business numbers for this peer group over the last $days — the "why" the
     * clustering page shows: scale (stores, SKUs) and trade (units, revenue).
     *
     * @return array{stores:int, units:float, revenue:float, avg_revenue:float, skus:int}
     */
    public function metrics(int $days = 90): array
    {
        if ($this->metricsMemo !== null) {
            return $this->metricsMemo;
        }

        $storeIds = $this->stores()->pluck('stores.id');
        $stores = $storeIds->count();

        if ($stores === 0) {
            return $this->metricsMemo = [
                'stores' => 0, 'units' => 0.0, 'revenue' => 0.0, 'avg_revenue' => 0.0, 'skus' => 0,
            ];
        }

        $agg = DB::table('sales_transactions')
            ->whereIn('store_id', $storeIds)
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->selectRaw('COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(quantity), 0) as units, COUNT(DISTINCT sku) as skus')
            ->first();

        $revenue = (float) ($agg->revenue ?? 0);

        return $this->metricsMemo = [
            'stores'      => $stores,
            'units'       => (float) ($agg->units ?? 0),
            'revenue'     => $revenue,
            'avg_revenue' => $revenue / $stores,
            'skus'        => (int) ($agg->skus ?? 0),
        ];
    }

    /** One-line business rationale for why these stores are grouped. */
    public function rationale(): string
    {
        $format = $this->params['format'] ?? null;
        $region = $this->params['region'] ?? null;

        if ($format || $region) {
            return 'Stores sharing format “' . ($format ?: 'unspecified')
                . '” in ' . ($region ?: 'an unspecified region')
                . ' — a like-for-like peer group.';
        }

        return 'A custom group you defined.';
    }
}
