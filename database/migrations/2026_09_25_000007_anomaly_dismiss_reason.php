<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP4.3 (audit H14) — why an anomaly was dismissed. Only "false_positive"
 * feeds sensitivity back into the baselines. A nullable column: a catalogue
 * change, no table rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('anomalies', 'dismiss_reason')) {
            return;
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET lock_timeout = '5s'");
        }
        Schema::table('anomalies', fn (Blueprint $t) => $t->string('dismiss_reason', 32)->nullable());
    }

    public function down(): void
    {
        if (Schema::hasColumn('anomalies', 'dismiss_reason')) {
            Schema::table('anomalies', fn (Blueprint $t) => $t->dropColumn('dismiss_reason'));
        }
    }
};
