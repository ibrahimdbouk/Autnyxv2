<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3.2 — tenant-defined rules (custom exception/detection conditions). `condition`
 * is a safe boolean JSON AST evaluated against a variable bag by the shared
 * Evaluator. The platform stores and evaluates them; wiring the fired rules into
 * the Root-Cause detection engine is a later consumer step (that engine is not
 * touched here). Adding a rule is an INSERT — no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_rule_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('key');
            $table->string('label');
            $table->json('condition');             // safe boolean AST
            $table->string('severity', 20)->default('warning'); // info|warning|critical
            $table->string('objective')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_rule_definitions');
    }
};
