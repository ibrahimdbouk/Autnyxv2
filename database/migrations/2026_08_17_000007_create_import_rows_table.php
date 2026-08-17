<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_data');       // original row values keyed by source header
            $table->json('mapped_data')->nullable(); // data after column mapping applied
            $table->text('error_message');
            $table->string('status')->default('pending_review');
            // pending_review | approved | rejected
            $table->timestamps();

            $table->index(['import_id', 'status']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
