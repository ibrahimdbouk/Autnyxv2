<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained()->cascadeOnDelete();

            // The anomaly that contributed this entity to the investigation
            $table->unsignedBigInteger('anomaly_id')->nullable();
            $table->foreign('anomaly_id')->references('id')->on('anomalies')->nullOnDelete();

            // Entity descriptor — what is being investigated
            // entity_type: sku | store | supplier | category | rule
            $table->string('entity_type');
            $table->string('entity_key');   // the SKU, store name, supplier name, rule_type, etc.

            // Optional rich references
            $table->unsignedBigInteger('store_id')->nullable();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();

            $table->timestamps();

            // One anomaly → at most one entity row per investigation
            $table->unique(['investigation_id', 'anomaly_id', 'entity_type', 'entity_key'], 'inv_entity_unique');
            $table->index(['investigation_id', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_entities');
    }
};
