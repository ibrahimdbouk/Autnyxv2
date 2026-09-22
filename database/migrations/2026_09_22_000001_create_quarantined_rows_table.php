<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quarantine — rows the Data Quality Firewall rejected before they could reach the
 * canonical tables detection reads. Each carries the raw + cleansed values, a typed
 * reason code and lineage, so the Quarantine triage console can group, fix, skip or
 * promote them. See claude/data-quality-firewall.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quarantined_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_id')->nullable()->constrained()->nullOnDelete();

            $table->string('data_type', 40);
            $table->unsignedInteger('row_number')->nullable();

            $table->jsonb('raw_data')->nullable();       // the row as received
            $table->jsonb('cleansed_data')->nullable();  // after cleansing, pre-reject

            $table->string('reason_code', 40);           // typed — see Reasons
            $table->string('severity', 10)->default('medium'); // low | medium | high
            $table->text('message')->nullable();

            // open | skipped | promoted | resolved
            $table->string('status', 12)->default('open');

            $table->timestamps();

            $table->index(['tenant_id', 'status', 'reason_code']);
            $table->index(['tenant_id', 'import_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quarantined_rows');
    }
};
