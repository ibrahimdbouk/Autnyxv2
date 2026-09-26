<?php

/*
|--------------------------------------------------------------------------
| Assortment Intelligence (App 2) — engine presets
|--------------------------------------------------------------------------
|
| Owned by Autnyx, not a customer knob-farm. Every figure here is a v1 default
| from the build scope (claude/assortment-build-scope.md §4) and is calibrated
| from measured outcomes later. The engine runs in SHADOW mode: it computes and
| stores decisions but nothing is shown to users until the validation gate
| passes for a tenant (`shadow` = true).
|
*/

return [
    // Global kill switch. While true, a tenant's decisions stay in 'shadow' until
    // that tenant passes the validation gate (tenants.settings.assortment.live).
    // Set false only to skip the gate everywhere (never in production).
    'shadow' => (bool) env('ASSORTMENT_SHADOW', true),

    // Validation gate: the top N decisions of each type are reviewed by a person;
    // the tenant goes live when at least this share are judged sensible.
    'gate_sample'   => 50,
    'gate_pass'     => 0.70,

    // Measuring an accepted decision: this many days before and after it was done.
    'measure_days' => 56,

    // A product is "carried" if it had stock or a sale in the last N days.
    'carried_window_days' => 56,

    // SKUs read per pass through a peer group — bounds memory whatever the range size.
    'chunk_skus' => 400,

    // Window the peer benchmark and the store's own performance are read over.
    'benchmark_window_days' => 90,

    // Peer groups: smaller clusters fall back to every store of the same format.
    'min_peer_group' => 5,

    // A peer only counts if it has carried the product this long and kept it in stock this often.
    'peer_min_carried_days'  => 28,
    'peer_min_availability'  => 0.80,

    // Add: carried by at least this share of peers (and at least N qualifying peers),
    // and selling at or above this percentile of its category's products.
    'add_min_carried_share' => 0.50,
    'min_qualifying_peers'  => 3,
    'add_rate_percentile'   => 0.40,

    // Share of an added product's sales that is new to the store (the rest is taken
    // from similar products already on the shelf) — a range until outcomes calibrate it.
    'incremental_share_low'  => 0.30,
    'incremental_share_high' => 0.70,

    // Delist: carried long enough, usually in stock, far below peers, bottom of its category.
    'delist_min_carried_days'   => 90,
    'delist_min_availability'   => 0.85,
    'delist_max_peer_ratio'     => 0.30,
    'delist_bottom_share'       => 0.10,
    'delist_min_history_days'   => 182,   // no delists before 26 weeks of history
    'delist_min_tier'           => 'likely',
    'max_delists_per_category'  => 3,     // per store per run: a review is a few calls, not a cull
    'max_adds_per_category'     => 5,     // the most valuable adds per store × category per run

    // Stockout-hidden: the right product, but out of stock this often.
    'stockout_max_availability' => 0.70,
    'stockout_min_observations' => 4,

    // Confidence tiers from the number of peers and how much they disagree
    // (spread = (p75 - p25) / median of the peers' share index).
    'tiers' => [
        'established' => ['min_peers' => 8, 'max_spread' => 1.0, 'confidence' => 0.80],
        'likely'      => ['confidence' => 0.60],
        'speculative' => ['max_peers' => 4, 'min_spread' => 2.0, 'confidence' => 0.40],
    ],

    // Product statuses that mean "do not propose adding it".
    'inactive_statuses' => ['discontinued', 'delisted', 'inactive', 'obsolete', 'blocked'],
];
