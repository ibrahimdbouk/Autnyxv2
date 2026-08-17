<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('data_type'); // sales_transactions|inventory_levels|products|purchase_orders
            $table->string('status')->default('uploaded');
            // uploaded → mapping_review → importing → completed | completed_with_errors | failed
            $table->json('sample_rows')->nullable();   // first 10 rows for display / AI context
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'data_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
