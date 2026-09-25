<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP5.4 (D7, audit H33) — AI calls and tokens per tenant per day, for the
 * daily budget every AI call is checked against (and for Ops to see).
 */
return new class extends Migration
{
    public function up(): void
    {
        // The model that wrote an investigation's narrative (shown with it; API ai.model).
        if (! Schema::hasColumn('investigations', 'ai_model')) {
            Schema::table('investigations', fn (Blueprint $t) => $t->string('ai_model', 64)->nullable());
        }
        if (Schema::hasTable('ai_usage_daily')) {
            return;
        }
        Schema::create('ai_usage_daily', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->date('day');
            $t->unsignedInteger('calls')->default(0);
            $t->unsignedBigInteger('input_tokens')->default(0);
            $t->unsignedBigInteger('output_tokens')->default(0);
            $t->unsignedInteger('refused')->default(0);
            $t->timestamps();
            $t->unique(['tenant_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_daily');
        if (Schema::hasColumn('investigations', 'ai_model')) {
            Schema::table('investigations', fn (Blueprint $t) => $t->dropColumn('ai_model'));
        }
    }
};
