<?php

namespace App\Services\Assortment;

use App\Models\AssortmentBenchmark;
use App\Models\Store;
use App\Models\StoreCluster;
use App\Platform\Intelligence\Clustering\ClusterService;
use Illuminate\Support\Str;

/**
 * Assortment — which stores a store is compared with.
 *
 * A store is judged against its cluster (the platform's peer groups) when the
 * cluster has at least `min_peer_group` stores; otherwise against every store of
 * the same format; if that is still too small, it is not judged at all (a gap
 * from two peers is noise, not evidence). Nothing to set up per tenant.
 */
class PeerGroups
{
    public function __construct(private ClusterService $clusters) {}

    /**
     * @return array{
     *   groups: array<string, array{key:string, basis:string, label:string, members:array<int,int>}>,
     *   assignment: array<int,string>,
     *   unjudged: array<int,int>
     * }
     *   groups: peer group key => its member store ids (the stores it is benchmarked over);
     *   assignment: store id => the peer group it is judged against;
     *   unjudged: stores with no big-enough peer group.
     */
    public function build(int $tenantId): array
    {
        $min    = max(2, (int) config('assortment.min_peer_group', 5));
        $stores = Store::query()->where('tenant_id', $tenantId)->get(['id', 'format']);

        $formatOf = [];
        $byFormat = [];
        foreach ($stores as $s) {
            $f = Str::slug(trim((string) $s->format)) ?: 'unspecified';
            $formatOf[$s->id] = $f;
            $byFormat[$f][] = (int) $s->id;
        }

        $groups = [];
        $assignment = [];

        $method = $this->clusters->activeMethod($tenantId);
        foreach ($this->clusters->clustersFor($tenantId, $method, StoreCluster::OBJECTIVE_GENERAL) as $cluster) {
            $members = $cluster->stores->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (count($members) < $min) {
                continue;
            }
            $key = 'cluster:' . $cluster->id;
            $groups[$key] = ['key' => $key, 'basis' => AssortmentBenchmark::BASIS_CLUSTER, 'label' => (string) $cluster->label, 'members' => $members];
            foreach ($members as $id) {
                $assignment[$id] = $key;
            }
        }

        $unjudged = [];
        foreach ($stores as $s) {
            $id = (int) $s->id;
            if (isset($assignment[$id])) {
                continue;
            }
            $f = $formatOf[$id];
            if (count($byFormat[$f]) < $min) {
                $unjudged[] = $id;

                continue;
            }
            $key = 'format:' . $f;
            $groups[$key] ??= [
                'key' => $key, 'basis' => AssortmentBenchmark::BASIS_FORMAT,
                'label' => 'All ' . Str::headline($f) . ' stores', 'members' => $byFormat[$f],
            ];
            $assignment[$id] = $key;
        }

        return ['groups' => $groups, 'assignment' => $assignment, 'unjudged' => $unjudged];
    }
}
