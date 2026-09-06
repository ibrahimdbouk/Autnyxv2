<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3.4 — per-tenant, per-app usage meters. One counter per
 * (tenant, app, metric, period) — e.g. (Autnyx, root_cause, investigations,
 * 2026-09). This is what makes per-app usage metered/billable and lets the
 * platform package/price each app independently — the metering half of the P3.4
 * definition of done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_meters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('app', 40);        // root_cause | assortment | tasks | …
            $table->string('metric', 60);     // investigations | detections | api_calls | …
            $table->string('period', 7);      // YYYY-MM
            $table->unsignedBigInteger('count')->default(0);

            $table->timestamps();

            $table->unique(['tenant_id', 'app', 'metric', 'period'], 'usage_meters_unique');
            $table->index(['tenant_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_meters');
    }
};
