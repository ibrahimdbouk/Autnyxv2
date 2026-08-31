<?php

use App\Models\StoreCluster;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrate the old tenant-level freeze to pins: for any tenant that had customised
 * its clusters, snapshot its current (general) grouping into cluster_pins so the
 * new pins-overlay rebuild preserves exactly what they had. Tenants that never
 * customised get no pins and re-cluster freely. Best-effort and idempotent-ish
 * (only runs for flagged tenants, once).
 */
return new class extends Migration
{
    public function up(): void
    {
        Tenant::query()->get()->each(function (Tenant $tenant) {
            $settings = $tenant->settings ?? [];
            if (empty($settings['clustering_customised'])) {
                return;
            }

            $clusters = StoreCluster::query()
                ->with('stores')
                ->where('tenant_id', $tenant->id)
                ->where('objective', 'general')
                ->get();

            foreach ($clusters as $cluster) {
                DB::table('cluster_pins')->insert([
                    'tenant_id'  => $tenant->id,
                    'objective'  => 'general',
                    'store_id'   => null,
                    'pin_type'   => 'rename',
                    'target_key' => $cluster->key,
                    'label'      => $cluster->label,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($cluster->stores as $store) {
                    DB::table('cluster_pins')->insert([
                        'tenant_id'  => $tenant->id,
                        'objective'  => 'general',
                        'store_id'   => $store->id,
                        'pin_type'   => 'membership',
                        'target_key' => $cluster->key,
                        'label'      => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // No-op: pins are the source of truth going forward.
    }
};
