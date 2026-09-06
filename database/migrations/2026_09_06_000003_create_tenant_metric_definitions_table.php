<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3.2 — tenant-defined KPIs. Extends the P1.4 metric layer: platform metrics are
 * registered in code; these are added by a tenant as data rows and merged in at
 * resolve time. `expression` is a safe JSON AST (const/var/op) evaluated by
 * Platform\Extensibility\Expression\Evaluator — never eval'd. Adding a KPI is an
 * INSERT, not a migration — that's the whole point of P3.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_metric_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('key');                 // unique per tenant
            $table->string('label');
            $table->string('unit', 20)->default('ratio'); // money|percent|count|ratio|days
            $table->text('description')->nullable();

            $table->json('expression');            // safe AST over base metrics/features
            $table->string('objective')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_metric_definitions');
    }
};
