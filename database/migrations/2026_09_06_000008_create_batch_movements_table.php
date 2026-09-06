<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3.3 — the chain of custody for a batch: every receipt, transfer, sale,
 * adjustment, disposal or return, in time order. This is what makes traceability
 * "end-to-end" — given a lot (e.g. on recall), you can trace where it went and how
 * much is still on hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();

            // receipt | transfer | sale | adjustment | disposal | return
            $table->string('movement_type', 20);
            $table->string('from_location')->nullable();
            $table->string('to_location')->nullable();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->string('reference')->nullable();
            $table->timestamp('occurred_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'batch_id']);
            $table->index(['batch_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_movements');
    }
};
