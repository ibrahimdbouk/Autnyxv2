<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.8 — per-tenant autonomy configuration for the orchestration engine. `level`
 * decides how independently the platform acts on a decision: advise (stop at the
 * recommendation), approve (stage it for a human), or auto (execute when safe).
 * `min_confidence` is the bar that AUTO must clear before it fires on its own.
 * intent_type is the specific rule ('reorder', 'transfer', …) or '*' for the
 * tenant default. This is the switch that makes autonomy a deliberate, tenant-owned
 * choice — never on by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autonomy_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('intent_type', 40)->default('*'); // '*' = tenant default
            $table->string('level', 20)->default('advise');  // advise | approve | auto
            $table->double('min_confidence')->default(0.8);   // AUTO must clear this
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['tenant_id', 'intent_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autonomy_policies');
    }
};
