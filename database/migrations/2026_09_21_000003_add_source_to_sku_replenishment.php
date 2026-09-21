<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks each replenishment row as 'computed' (the nightly ReplenishmentService)
 * or 'ingested' (tenant-supplied via an F&R params feed). The compute defers to
 * ingested rows — it never overwrites or deletes them — so authoritative
 * parameters from RELEX / Blue Yonder / Slimstock take precedence.
 * See claude/api-integration-library.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sku_replenishment', function (Blueprint $table) {
            $table->string('source')->default('computed')->after('service_level'); // computed | ingested
            $table->index(['tenant_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('sku_replenishment', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source']);
            $table->dropColumn('source');
        });
    }
};
