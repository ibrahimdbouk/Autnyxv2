<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — the per-import quality row becomes the batch DECISION record: a
 * GREEN/AMBER/RED state, a human-readable decision, and a blocked flag that the
 * detection-readiness contract reads. See claude/data-quality-firewall.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_quality', function (Blueprint $table) {
            $table->string('source', 60)->nullable()->after('data_type'); // feed / origin label
            $table->string('state', 8)->default('green')->after('is_duplicate_file'); // green|amber|red
            $table->string('decision')->nullable()->after('state');
            $table->boolean('blocked')->default(false)->after('decision');

            $table->index(['tenant_id', 'data_type', 'created_at'], 'iq_readiness_idx');
        });
    }

    public function down(): void
    {
        Schema::table('import_quality', function (Blueprint $table) {
            $table->dropIndex('iq_readiness_idx');
            $table->dropColumn(['source', 'state', 'decision', 'blocked']);
        });
    }
};
