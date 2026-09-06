<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2.1 — a tenant's outbound target: the replenishment system of record we write
 * decisions back to. `kind` selects the connector (webhook / log today; s4 /
 * d365 / oracle / relex as flagship connectors land). Everything else routes
 * through the generic webhook + the customer's iPaaS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // webhook | log | s4 | d365 | oracle | relex | blue_yonder | slimstock | …
            $table->string('kind', 30);
            $table->string('name')->nullable();
            $table->string('endpoint')->nullable();

            // Connector config — signing secret, headers, credential references, etc.
            $table->json('config')->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_targets');
    }
};
