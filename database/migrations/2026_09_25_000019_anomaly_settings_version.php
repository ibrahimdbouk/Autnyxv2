<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP7.4 (audit L9) — anomaly_settings.thresholds holds the tenant's own
 * overrides only; settings_version records which code defaults a row was
 * last reconciled against (AnomalySetting::SETTINGS_VERSION). Rows start at 0
 * and are reconciled by `anomaly-settings:sync --apply`; until then reads
 * reconcile on the fly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anomaly_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('settings_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('anomaly_settings', function (Blueprint $table) {
            $table->dropColumn('settings_version');
        });
    }
};
