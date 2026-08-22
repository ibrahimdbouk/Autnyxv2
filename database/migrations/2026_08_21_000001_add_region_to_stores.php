<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a region column to stores so the Stores master import can capture it
 * (enables region-level grouping in recovery reporting, and richer store
 * enrichment for the location_proliferation rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'region')) {
                $table->string('region')->nullable()->after('city');
            }
            $table->index(['tenant_id', 'region']);
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'region']);
            $table->dropColumn('region');
        });
    }
};
