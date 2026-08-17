<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_column_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->string('source_header');        // original column name from file
            $table->string('target_field')->nullable(); // our canonical field name
            $table->float('confidence')->default(0); // 0.0–1.0 from AI
            $table->string('reasoning')->nullable();    // AI's one-line explanation
            $table->boolean('is_confirmed')->default(false);
            $table->boolean('is_skipped')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('import_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_column_maps');
    }
};
