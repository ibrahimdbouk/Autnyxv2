<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound F&R exceptions — the alerts a tenant's planning system (RELEX / Blue
 * Yonder / Slimstock) raises itself, ingested so Autnyx can act on them without
 * duplicating that tool. Hybrid disposition (see PlanningExceptionIngestor):
 *
 *  - `corroboration` — a type Autnyx ALSO detects (forecast/stockout/excess/…):
 *    the exception attaches to a matching open Autnyx anomaly as extra evidence
 *    and can raise its severity; unmatched ones stay here as a review signal,
 *    never a standalone investigation.
 *  - `first_class` — a type Autnyx CANNOT derive from ERP+POS data (upstream
 *    supply / allocation / service-level / capacity risk): it raises its own
 *    Autnyx anomaly (rule_type `planning_exception`).
 *
 * A poll is treated as the current-open snapshot per source, so an exception
 * that drops out of the feed is reconciled to `resolved`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('source', 30);                 // relex | blue_yonder | slimstock | …
            $table->string('external_ref')->nullable();   // upstream alert id (idempotency)

            $table->string('sku');
            $table->unsignedBigInteger('store_id')->nullable(); // null = chain-level

            $table->string('external_type')->nullable();  // the upstream type verbatim
            $table->string('category', 30);               // normalised — see the ingestor
            $table->string('disposition', 20);            // corroboration | first_class
            $table->string('severity', 20)->default('medium'); // low | medium | high
            $table->text('message')->nullable();
            $table->date('occurred_at')->nullable();
            $table->json('payload')->nullable();

            $table->string('status', 20)->default('open'); // open | matched | resolved
            $table->unsignedBigInteger('matched_anomaly_id')->nullable(); // corroboration target
            $table->unsignedBigInteger('anomaly_id')->nullable();         // first-class anomaly raised

            $table->timestamps();

            $table->index(['tenant_id', 'source', 'status']);
            $table->index(['tenant_id', 'sku', 'store_id']);
            $table->index(['tenant_id', 'disposition', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_exceptions');
    }
};
