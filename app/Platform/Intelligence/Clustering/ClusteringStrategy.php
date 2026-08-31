<?php

namespace App\Platform\Intelligence\Clustering;

/**
 * A pluggable store-clustering strategy. Build the interface now; let the
 * implementation grow with data (attribute v0 -> demand v1) behind it.
 *
 * cluster() is pure: it computes groups from tenant data and returns them. It
 * does NOT persist — ClusterService owns persistence — so strategies stay
 * testable and swappable.
 */
interface ClusteringStrategy
{
    /** Stable identifier persisted on each cluster (e.g. 'attribute', 'demand'). */
    public function method(): string;

    /**
     * Compute the peer groups for a tenant.
     *
     * @return array<int, array{key: string, label: string, store_ids: list<int>, params?: array}>
     */
    public function cluster(int $tenantId): array;
}
