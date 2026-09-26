<?php

namespace App\Services\Org;

use App\Models\LocationNode;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Platform core — who works where, and who is above whom.
 *
 * One place every app asks:
 *   • storeScope(user): the stores a person's store-level screens and work
 *     cover (null = every store);
 *   • escalationChain(store): store managers → area managers → region
 *     managers → head office, skipping empty levels.
 *
 * The chain is read from the location tree (region → area → store, built by
 * HierarchySync from the store's region and area) and the managers assigned
 * to its nodes. Deactivated people never appear.
 */
class OrgDirectory
{
    public const LEVEL_STORE  = 'store';
    public const LEVEL_AREA   = 'area';
    public const LEVEL_REGION = 'region';
    public const LEVEL_HQ     = 'hq';

    /** @return array<int,int>|null null = every store */
    public function storeScope(User $user): ?array
    {
        if ($user->is_super_admin || $user->is_tenant_admin) {
            return null;
        }

        $linked = $this->linkedStoreIds($user);

        return match ($user->org_role) {
            User::ORG_HQ => null,
            User::ORG_AREA_MANAGER => array_values(array_unique(array_merge($this->managedStoreIds($user), $linked))),
            User::ORG_STORE_MANAGER, User::ORG_ASSOCIATE => $linked,
            // No operating role set: before the role ladder, an unlinked user saw every store.
            default => $linked === [] ? null : $linked,
        };
    }

    /** @return array<int,int> */
    public function linkedStoreIds(User $user): array
    {
        return DB::table('store_user')->where('user_id', $user->id)->where('tenant_id', $user->tenant_id)
            ->pluck('store_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Every store under the regions / areas this person manages.
     *
     * @return array<int,int>
     */
    public function managedStoreIds(User $user): array
    {
        $rows = DB::select(
            "WITH RECURSIVE sub AS (
                 SELECT n.id FROM location_nodes n
                   JOIN location_node_managers m ON m.location_node_id = n.id
                  WHERE m.user_id = ? AND n.tenant_id = ?
                 UNION
                 SELECT c.id FROM location_nodes c JOIN sub ON c.parent_id = sub.id WHERE c.tenant_id = ?
             )
             SELECT DISTINCT n.store_id FROM location_nodes n
              WHERE n.id IN (SELECT id FROM sub) AND n.type = 'store' AND n.store_id IS NOT NULL",
            [$user->id, $user->tenant_id, $user->tenant_id]
        );

        return array_map(fn ($r) => (int) $r->store_id, $rows);
    }

    /**
     * The store's region and area nodes, nearest first.
     *
     * @return array<int,LocationNode>
     */
    public function nodesAbove(Store $store): array
    {
        $leaf = LocationNode::where('tenant_id', $store->tenant_id)->where('type', LocationNode::TYPE_STORE)
            ->where('store_id', $store->id)->first();

        return $leaf ? $leaf->ancestors()->all() : [];
    }

    /** Active people who run this store (store managers; linked people with no role set count too). */
    public function storeManagers(Store $store): Collection
    {
        return User::active()->where('users.tenant_id', $store->tenant_id)
            ->whereIn('users.id', DB::table('store_user')->where('store_id', $store->id)->select('user_id'))
            ->where(fn ($q) => $q->where('org_role', User::ORG_STORE_MANAGER)->orWhereNull('org_role'))
            ->where('is_super_admin', false)
            ->orderBy('name')->get();
    }

    /** Active head-office people; the tenant's admins when nobody has the head-office role. */
    public function headOffice(int $tenantId): Collection
    {
        $hq = User::active()->where('tenant_id', $tenantId)->where('org_role', User::ORG_HQ)->orderBy('name')->get();

        return $hq->isNotEmpty() ? $hq
            : User::active()->where('tenant_id', $tenantId)->where('is_tenant_admin', true)->where('is_super_admin', false)->orderBy('name')->get();
    }

    /**
     * Who a problem at this store goes to, level by level, nearest first.
     * Levels with nobody on them are left out; head office always ends it.
     *
     * @return array<int,array{level:string, node:?LocationNode, users:Collection}>
     */
    public function escalationChain(Store $store): array
    {
        $chain = [];

        $managers = $this->storeManagers($store);
        if ($managers->isNotEmpty()) {
            $chain[] = ['level' => self::LEVEL_STORE, 'node' => null, 'users' => $managers];
        }

        foreach ($this->nodesAbove($store) as $node) {
            $level = match ($node->type) {
                LocationNode::TYPE_AREA   => self::LEVEL_AREA,
                LocationNode::TYPE_REGION => self::LEVEL_REGION,
                default                   => null,
            };
            if ($level === null) {
                continue;
            }
            $users = $node->managers()->whereNull('users.deactivated_at')->orderBy('users.name')->get();
            if ($users->isNotEmpty()) {
                $chain[] = ['level' => $level, 'node' => $node, 'users' => $users];
            }
        }

        $hq = $this->headOffice((int) $store->tenant_id);
        if ($hq->isNotEmpty()) {
            $chain[] = ['level' => self::LEVEL_HQ, 'node' => null, 'users' => $hq];
        }

        return $chain;
    }

    /** The regions and areas a person may be put in charge of (for pickers). */
    public static function nodeOptions(int $tenantId): array
    {
        $nodes = LocationNode::where('tenant_id', $tenantId)
            ->whereIn('type', [LocationNode::TYPE_REGION, LocationNode::TYPE_AREA])
            ->with('parent')->orderBy('type', 'desc')->orderBy('name')->get();

        return $nodes->mapWithKeys(fn (LocationNode $n) => [
            $n->id => ($n->type === LocationNode::TYPE_AREA ? 'Area: ' : 'Region: ')
                . ($n->type === LocationNode::TYPE_AREA && $n->parent ? $n->parent->name . ' › ' : '') . $n->name,
        ])->all();
    }
}
