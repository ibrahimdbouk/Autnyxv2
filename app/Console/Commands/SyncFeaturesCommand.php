<?php

namespace App\Console\Commands;

use App\Models\StoreFeature;
use App\Platform\Features\FeatureStore;
use Illuminate\Console\Command;

/**
 * P1.5 — project the wide store_features table into the generic feature store,
 * demonstrating the unification: the same per-store features become uniform
 * (entity_type=store, entity_key=store_id) feature rows any consumer can read.
 * Idempotent per day (as_of = today).
 */
class SyncFeaturesCommand extends Command
{
    protected $signature = 'features:sync {--tenant= : Tenant id (default: all)}';

    protected $description = 'Project store_features into the generic feature store (feature_values).';

    /** @var array<int,string> */
    private array $numeric = [
        'revenue', 'units', 'active_skus', 'basket_count', 'avg_daily_revenue', 'growth_ratio',
        'avg_basket_value', 'avg_basket_units', 'avg_selling_price', 'sku_productivity',
        'promo_share', 'top_category_share',
    ];

    /** @var array<int,string> */
    private array $text = [
        'descriptor', 'size_tier', 'price_tier', 'basket_tier', 'top_category', 'dominant_segment',
    ];

    public function handle(FeatureStore $store): int
    {
        $tenant = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $asOf   = now()->toDateString();
        $count  = 0;

        StoreFeature::query()
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($store, $asOf, &$count) {
                foreach ($rows as $feature) {
                    foreach (array_merge($this->numeric, $this->text) as $column) {
                        if ($feature->{$column} !== null && $feature->{$column} !== '') {
                            $store->put($feature->tenant_id, 'store', $feature->store_id, $column, $feature->{$column}, $asOf);
                            $count++;
                        }
                    }
                }
            });

        $this->info("Synced {$count} feature value(s) from store features.");

        return self::SUCCESS;
    }
}
