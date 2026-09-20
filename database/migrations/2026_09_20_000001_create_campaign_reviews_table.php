<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * campaign_reviews — the tempo layer for the Action Queue.
 *
 * Records the last time each campaign was reviewed for a tenant, so the queue
 * can give campaigns an explicit cadence (daily / weekly / …) and surface which
 * are "due" now rather than presenting everything every day. One row per
 * (tenant, campaign).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_reviews')) {
            return;
        }

        Schema::create('campaign_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('campaign');
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'campaign']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_reviews');
    }
};
