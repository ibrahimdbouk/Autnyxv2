<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP1.1 (audit C1) — the AI narrator's money guess gets its own, clearly
 * labelled column. `revenue_at_risk` stays deterministic (Σ anomaly
 * revenue_impact) and is never written from model output again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('investigations') && ! Schema::hasColumn('investigations', 'ai_revenue_estimate')) {
            Schema::table('investigations', function (Blueprint $t) {
                $t->decimal('ai_revenue_estimate', 14, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('investigations') && Schema::hasColumn('investigations', 'ai_revenue_estimate')) {
            Schema::table('investigations', fn (Blueprint $t) => $t->dropColumn('ai_revenue_estimate'));
        }
    }
};
