<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP4.4 (audit H21) — what kind of money an anomaly carries (lost revenue,
 * capital at cost, upside, data quality), and an investigation's capital
 * figure kept apart from its revenue at risk. Nullable columns only; existing
 * rows are classified by `anomalies:classify-value` (a command, not here).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET lock_timeout = '5s'");
        }
        if (! Schema::hasColumn('anomalies', 'value_type')) {
            Schema::table('anomalies', fn (Blueprint $t) => $t->string('value_type', 20)->nullable());
        }
        if (! Schema::hasColumn('investigations', 'capital_at_risk')) {
            Schema::table('investigations', fn (Blueprint $t) => $t->decimal('capital_at_risk', 18, 2)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('anomalies', 'value_type')) {
            Schema::table('anomalies', fn (Blueprint $t) => $t->dropColumn('value_type'));
        }
        if (Schema::hasColumn('investigations', 'capital_at_risk')) {
            Schema::table('investigations', fn (Blueprint $t) => $t->dropColumn('capital_at_risk'));
        }
    }
};
