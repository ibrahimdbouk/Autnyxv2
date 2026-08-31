<?php

use App\Platform\Intelligence\Clustering\Strategies\AttributeClustering;

return [

    /*
    |--------------------------------------------------------------------------
    | Store clustering strategy
    |--------------------------------------------------------------------------
    |
    | Which pluggable strategy `clusters:rebuild` uses. 'attribute' groups stores
    | by format + region (works day one, no data-maturity dependency). A learned
    | 'demand' strategy can be added to the map below and swapped in via env once
    | there is enough history — no consumer changes, they read through ClusterService.
    |
    */

    'strategy' => env('CLUSTERING_STRATEGY', 'attribute'),

    'strategies' => [
        'attribute' => AttributeClustering::class,
    ],

];
