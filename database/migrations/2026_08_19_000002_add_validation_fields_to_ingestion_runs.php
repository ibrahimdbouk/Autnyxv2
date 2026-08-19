<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M23 / Feature 4 — Data Health Center
 *
 * Extends the existing ingestion_runs table (M17) with validation scoring
 * so the Data Health Center can grade each ingestion deterministically.
 * We do NOT create a second ingestion/upload-history concept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingestion_runs', function (Blueprint $table) {
            // Deterministic 0–100 validation score for this run
            $table->decimal('validation_score', 5, 2)->nullable()->after('rows_skipped');
            // Structured non-fatal warnings (referential gaps, duplicates, volume anomalies…)
            $table->json('warnings')->nullable()->after('error_sample');
            // Sample of rejected rows for drill-down / export (up to 50)
            $table->json('rejected_sample')->nullable()->after('warnings');
            // Diagnostic counts
            $table->unsignedInteger('duplicate_count')->default(0)->after('rows_skipped');
            $table->unsignedInteger('referential_issue_count')->default(0)->after('duplicate_count');
        });
    }

    public function down(): void
    {
        Schema::table('ingestion_runs', function (Blueprint $table) {
            $table->dropColumn([
                'validation_score',
                'warnings',
                'rejected_sample',
                'duplicate_count',
                'referential_issue_count',
            ]);
        });
    }
};
