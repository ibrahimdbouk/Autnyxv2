<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W10 (WP10.6) — tenant-authored rules and KPIs get a screen. The admin types
 * a formula ("days_of_cover < 3 and units_7d > 20"); it is compiled to the safe
 * AST already stored in `condition` / `expression`, and the text is kept so it
 * can be read and edited. A rule may also say what a hit is worth (an impact
 * formula) and what kind of money that is (value_type, as for built-in rules).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_rule_definitions', function (Blueprint $table) {
            $table->text('formula')->nullable()->after('label');
            $table->text('impact_formula')->nullable()->after('condition');
            $table->json('impact')->nullable()->after('impact_formula');
            $table->string('value_type', 30)->nullable()->after('impact');
            $table->text('description')->nullable()->after('value_type');
        });
        Schema::table('custom_metric_definitions', function (Blueprint $table) {
            $table->text('formula')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('custom_rule_definitions', function (Blueprint $table) {
            $table->dropColumn(['formula', 'impact_formula', 'impact', 'value_type', 'description']);
        });
        Schema::table('custom_metric_definitions', function (Blueprint $table) {
            $table->dropColumn('formula');
        });
    }
};
