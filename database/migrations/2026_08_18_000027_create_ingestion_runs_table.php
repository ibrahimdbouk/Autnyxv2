<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // What was ingested
            $table->string('data_type');   // sales | inventory | purchase_orders | products
            $table->string('source')->default('csv'); // csv | excel | api | sftp

            // Status machine
            $table->string('status')->default('pending'); // pending | running | completed | failed | partial

            // File / request metadata
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();

            // Counts
            $table->unsignedInteger('rows_processed')->default(0);
            $table->unsignedInteger('rows_imported')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);

            // Timing
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Error detail
            $table->text('error_message')->nullable();
            $table->json('error_sample')->nullable(); // up to 10 failed row samples

            // Back-reference to legacy imports table (nullable for forward-only runs)
            $table->unsignedBigInteger('import_id')->nullable();
            $table->index('import_id');

            $table->timestamps();

            $table->index(['tenant_id', 'data_type', 'status']);
            $table->index(['tenant_id', 'completed_at']);
            $table->index(['tenant_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_runs');
    }
};
