<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained()->cascadeOnDelete();

            // Which anomaly this evidence relates to (null = investigation-level)
            $table->unsignedBigInteger('anomaly_id')->nullable();
            $table->foreign('anomaly_id')->references('id')->on('anomalies')->nullOnDelete();

            // Evidence classification
            // evidence_type: data_point | stat | snapshot | import_run | calculation | threshold_breach
            $table->string('evidence_type');
            // source: which table/service produced this (sales_transactions, inventory_levels, baselines, etc.)
            $table->string('source');
            // Human-readable label shown in the UI and fed to the AI
            $table->string('label');

            // Value (only one of numeric/text/json will be populated per row)
            $table->decimal('value_numeric', 16, 4)->nullable();
            $table->string('value_text')->nullable();
            $table->json('value_json')->nullable();   // for time-series arrays or multi-value payloads
            $table->string('unit')->nullable();       // "units", "%", "$", "days", etc.

            // Hypothesis alignment
            // direction: supports | contradicts | neutral (relative to the anomaly's hypothesis)
            $table->string('direction')->default('neutral');
            // strength: strong | moderate | weak
            $table->string('strength')->default('moderate');

            // Temporal reference — when was this evidence observed/measured
            $table->timestamp('observed_at')->nullable();

            $table->timestamps();

            $table->index(['investigation_id', 'evidence_type']);
            $table->index(['investigation_id', 'direction']);
            $table->index(['investigation_id', 'anomaly_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_evidence');
    }
};
