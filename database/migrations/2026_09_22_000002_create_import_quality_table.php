<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-import data-quality summary produced by the firewall: how many rows promoted
 * vs quarantined vs cleansed, the reason breakdown, a first-chunk column profile
 * (null%, distinct, invalid…), and a re-upload fingerprint. Powers the Data Quality
 * dashboard and the per-import health view. See claude/data-quality-firewall.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_quality', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();

            $table->string('data_type', 40)->nullable();

            $table->unsignedInteger('rows_seen')->default(0);
            $table->unsignedInteger('rows_promoted')->default(0);
            $table->unsignedInteger('rows_quarantined')->default(0);
            $table->unsignedInteger('rows_cleansed')->default(0); // rows a rule changed

            $table->jsonb('reason_counts')->nullable();   // reason_code => count
            $table->jsonb('column_profile')->nullable();  // field => stats

            $table->string('file_fingerprint', 64)->nullable();
            $table->boolean('is_duplicate_file')->default(false);

            $table->timestamps();

            $table->unique('import_id');
            $table->index(['tenant_id', 'data_type']);
            $table->index(['tenant_id', 'file_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_quality');
    }
};
