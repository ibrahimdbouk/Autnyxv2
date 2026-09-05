<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1.5 — the feature store: a generic, versioned, time-series-capable home for
 * entity features. Where store_features / sku_profiles are per-domain wide
 * tables, this is a long/uniform store — (entity_type, entity_key, feature,
 * as_of, version) → value — that detection, clustering and forecasting can all
 * read through the same API. Additive; the wide tables stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('entity_type', 30);   // store | sku | product | …
            $table->string('entity_key');        // e.g. store_id or sku (as string)
            $table->string('feature', 60);       // feature name

            $table->decimal('value_num', 20, 6)->nullable();
            $table->string('value_text')->nullable();

            $table->date('as_of')->nullable();   // time-series axis (null = undated/current)
            $table->integer('version')->default(1);
            $table->timestamp('computed_at')->useCurrent();

            $table->unique(
                ['tenant_id', 'entity_type', 'entity_key', 'feature', 'as_of', 'version'],
                'feature_values_identity_unique'
            );
            $table->index(['tenant_id', 'entity_type', 'entity_key']);
            $table->index(['tenant_id', 'feature', 'as_of']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_values');
    }
};
