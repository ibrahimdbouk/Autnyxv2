<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('rule_type');
            $table->string('severity');          // low | medium | high
            $table->string('sku')->nullable();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description');
            $table->json('context')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('dismissed_at')->nullable();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'rule_type']);
            $table->index(['tenant_id', 'severity']);
            $table->index(['tenant_id', 'dismissed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anomalies');
    }
};
