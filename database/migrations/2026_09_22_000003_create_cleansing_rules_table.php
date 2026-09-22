<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant cleansing overrides layered on top of the firewall's built-in defaults.
 * Each row is one transform for one field of one data type, applied in `ordinal`
 * order. Empty table = defaults only. See claude/data-quality-firewall.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleansing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('data_type', 40);
            $table->string('field', 60);
            // trim | collapse_ws | upper | lower | strip_leading_zeros | date_iso |
            // number | default_if_blank | regex_replace | value_map
            $table->string('rule_type', 30);
            $table->jsonb('params')->nullable();

            $table->unsignedSmallInteger('ordinal')->default(100);
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->index(['tenant_id', 'data_type', 'field', 'ordinal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleansing_rules');
    }
};
