<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomaly_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('rule_type');
            $table->boolean('enabled')->default(true);
            $table->json('thresholds')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'rule_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anomaly_settings');
    }
};
