<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1.1 — Canonical Promotion hierarchy (campaign → promotion → offer), with each
 * node effective-dated by its active window (starts_at / ends_at). Unlike the
 * operational hierarchies a leaf is the promotion itself (no external ref); the
 * value is the effective-dated tree so any app can ask "which promotions were
 * active on date X, and under which campaign." No source data yet — this is the
 * scaffold the ingestion + event layers will populate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // campaign | promotion | offer
            $table->string('type', 20);

            $table->string('code')->nullable();
            $table->string('name');

            $table->unsignedBigInteger('parent_id')->nullable();

            // pct_off | amount_off | bogo | bundle | multibuy | …
            $table->string('mechanic')->nullable();

            // Effective-dated active window.
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();

            $table->json('attributes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_nodes');
    }
};
