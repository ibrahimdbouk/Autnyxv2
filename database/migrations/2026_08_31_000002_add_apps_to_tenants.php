<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform app entitlements — which Autnyx apps a tenant may use. Autnyx is a
 * platform with apps on top (Root-Cause built; Assortment, Task Execution next);
 * `tenants.apps` records the subset a tenant holds, assigned from /ops.
 *
 * Existing tenants are backfilled to Root-Cause so nothing changes for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('apps')->nullable()->after('status');
        });

        // Every current tenant keeps the one built app.
        DB::table('tenants')->whereNull('apps')->update([
            'apps' => json_encode(['root_cause']),
        ]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('apps');
        });
    }
};
