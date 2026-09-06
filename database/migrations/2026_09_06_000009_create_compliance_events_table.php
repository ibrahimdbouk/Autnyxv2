<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3.3 — compliance events against a batch (or tenant-wide): temperature
 * excursions (cold-chain breaches), expiry breaches, recalls, quarantine and
 * disposal. This is the regulated-vertical audit trail — what pharma/FMCG needs
 * that generic retail does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();

            // temperature_excursion | expiry_breach | recall | quarantine | disposal | other
            $table->string('event_type', 30);
            $table->string('severity', 20)->default('warning'); // info | warning | critical
            $table->text('detail')->nullable();

            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'event_type']);
            $table->index(['tenant_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_events');
    }
};
