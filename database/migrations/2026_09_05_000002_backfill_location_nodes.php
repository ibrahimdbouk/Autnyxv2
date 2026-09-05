<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the Location hierarchy from the existing flat stores table:
 *   - one `region` node per distinct non-null store region (per tenant)
 *   - one `store` leaf node per store, parented under its region node
 *
 * Idempotent-ish: skips a tenant that already has location_nodes, so re-running
 * (or a second deploy) does not duplicate. Interior banner/DC tiers are added
 * later when that data exists — the type column already supports them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stores = DB::table('stores')->get();

        foreach ($stores->groupBy('tenant_id') as $tenantId => $tenantStores) {
            // Don't double-backfill a tenant that already has nodes.
            if (DB::table('location_nodes')->where('tenant_id', $tenantId)->exists()) {
                continue;
            }

            // Region tier.
            $regionNodeId = [];
            foreach ($tenantStores->pluck('region')->filter()->unique() as $region) {
                $regionNodeId[$region] = DB::table('location_nodes')->insertGetId([
                    'tenant_id'      => $tenantId,
                    'type'           => 'region',
                    'name'           => $region,
                    'parent_id'      => null,
                    'store_id'       => null,
                    'attributes'     => null,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }

            // Store leaf tier.
            foreach ($tenantStores as $store) {
                $parentId = ($store->region && isset($regionNodeId[$store->region]))
                    ? $regionNodeId[$store->region]
                    : null;

                DB::table('location_nodes')->insert([
                    'tenant_id'  => $tenantId,
                    'type'       => 'store',
                    'code'       => $store->code ?? null,
                    'name'       => $store->name,
                    'parent_id'  => $parentId,
                    'store_id'   => $store->id,
                    'attributes' => json_encode(array_filter([
                        'city'    => $store->city ?? null,
                        'country' => $store->country ?? null,
                        'format'  => $store->format ?? null,
                    ])),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('location_nodes')->truncate();
    }
};
