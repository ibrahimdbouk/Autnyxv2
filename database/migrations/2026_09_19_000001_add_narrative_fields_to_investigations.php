<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Narration-quality upgrade — richer AI narrative fields so the 7-step panel
 * renders narrated text (plain-language evidence, contributing factors, impact,
 * headline, long-term fix) instead of raw evidence-row concatenation.
 * Additive and nullable; existing rows narrate into these on next run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investigations', function (Blueprint $table) {
            if (! Schema::hasColumn('investigations', 'ai_headline')) {
                $table->text('ai_headline')->nullable()->after('ai_summary');
            }
            if (! Schema::hasColumn('investigations', 'ai_evidence')) {
                $table->json('ai_evidence')->nullable()->after('ai_confidence');
            }
            if (! Schema::hasColumn('investigations', 'ai_contributing_factors')) {
                $table->json('ai_contributing_factors')->nullable()->after('ai_evidence');
            }
            if (! Schema::hasColumn('investigations', 'ai_business_impact')) {
                $table->text('ai_business_impact')->nullable()->after('ai_contributing_factors');
            }
            if (! Schema::hasColumn('investigations', 'ai_long_term_fix')) {
                $table->text('ai_long_term_fix')->nullable()->after('ai_recommended_action');
            }
        });
    }

    public function down(): void
    {
        Schema::table('investigations', function (Blueprint $table) {
            $table->dropColumn([
                'ai_headline',
                'ai_evidence',
                'ai_contributing_factors',
                'ai_business_impact',
                'ai_long_term_fix',
            ]);
        });
    }
};
