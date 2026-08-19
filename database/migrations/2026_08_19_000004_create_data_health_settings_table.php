<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M23 / Feature 4 — Data Health Center
 *
 * Tenant-configurable thresholds per dataset. Absent row => service defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_health_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('dataset');

            $table->integer('freshness_max_hours')->nullable();     // stale beyond this
            $table->decimal('completeness_min_pct', 5, 2)->nullable();
            $table->decimal('rejection_max_pct', 5, 2)->nullable();  // rejection-spike threshold

            $table->timestamps();

            $table->unique(['tenant_id', 'dataset']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_health_settings');
    }
};
