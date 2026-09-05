<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the Supplier hierarchy from the flat suppliers table:
 *   - one `group` node per distinct non-null supplier type (per tenant)
 *   - one `supplier` leaf per supplier, parented under its group (else root)
 *
 * Skips a tenant that already has supplier_nodes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $suppliers = DB::table('suppliers')->get();

        foreach ($suppliers->groupBy('tenant_id') as $tenantId => $tenantSuppliers) {
            if (DB::table('supplier_nodes')->where('tenant_id', $tenantId)->exists()) {
                continue;
            }

            // Group tier (from the supplier's business type).
            $groupNode = [];
            foreach ($tenantSuppliers->pluck('type')->filter()->unique() as $type) {
                $groupNode[$type] = DB::table('supplier_nodes')->insertGetId([
                    'tenant_id'   => $tenantId,
                    'type'        => 'group',
                    'name'        => $type,
                    'parent_id'   => null,
                    'supplier_id' => null,
                    'attributes'  => null,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            // Supplier leaf tier, batched.
            $leaves = [];
            foreach ($tenantSuppliers as $s) {
                $parentId = (! empty($s->type) && isset($groupNode[$s->type]))
                    ? $groupNode[$s->type]
                    : null;

                $leaves[] = [
                    'tenant_id'   => $tenantId,
                    'type'        => 'supplier',
                    'code'        => $s->code ?? null,
                    'name'        => $s->name,
                    'parent_id'   => $parentId,
                    'supplier_id' => $s->id,
                    'attributes'  => json_encode(array_filter([
                        'specialization' => $s->specialization ?? null,
                        'lead_time_days' => $s->lead_time_days ?? null,
                    ])),
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];

                if (count($leaves) >= 500) {
                    DB::table('supplier_nodes')->insert($leaves);
                    $leaves = [];
                }
            }

            if ($leaves !== []) {
                DB::table('supplier_nodes')->insert($leaves);
            }
        }
    }

    public function down(): void
    {
        DB::table('supplier_nodes')->truncate();
    }
};
