<?php

use App\Platform\Intelligence\Clustering\Strategies\AttributeClustering;
use App\Platform\Intelligence\Clustering\Strategies\DemandClustering;

return [

    /*
    |--------------------------------------------------------------------------
    | Store clustering strategy
    |--------------------------------------------------------------------------
    |
    | Which pluggable strategy `clusters:rebuild` uses. 'attribute' groups stores
    | by format + region (works day one, no data-maturity dependency). 'demand'
    | groups by behaviour from the store feature layer (Phase 3) — richer, but it
    | needs StoreProfiler to have run. Both read through ClusterService, so
    | switching is one env change; validate first with `clusters:shadow-diff`.
    |
    | Default stays 'attribute' — 'demand' ships dark until validated per tenant.
    |
    */

    'strategy' => env('CLUSTERING_STRATEGY', 'attribute'),

    'strategies' => [
        'attribute' => AttributeClustering::class,
        'demand'    => DemandClustering::class,
    ],

    // Behavioural (demand) clustering knobs.
    'demand' => [
        'max_k' => (int) env('CLUSTERING_DEMAND_MAX_K', 8),
    ],

];
