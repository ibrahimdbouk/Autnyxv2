<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.5 Phase 3 — the Assortment Decision Studio.
 *
 *   assortment_scenarios   a what-if for one store × category: its inputs (the
 *                          objective, limits, the changes the person picked, what
 *                          they protected or unprotected) and its result as it was
 *                          simulated when saved — so scenarios compare as saved,
 *                          not recalculated on every click.
 *   assortment_plans       + source (engine | studio), the scenario a Studio plan
 *                          came from, and who made it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assortment_scenarios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('category', 191);
            $t->string('name', 120);
            $t->string('preset', 24)->nullable();   // sales | margin | balanced | lean | availability | recommended; null = the person's own
            $t->json('inputs');
            $t->json('result');                     // summary + changes, as simulated when saved
            $t->date('as_of_date');                 // the data it was simulated on
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['tenant_id', 'store_id', 'category']);
        });

        Schema::table('assortment_plans', function (Blueprint $t) {
            $t->string('source', 12)->default('engine');
            $t->foreignId('scenario_id')->nullable()->constrained('assortment_scenarios')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assortment_plans', function (Blueprint $t) {
            $t->dropConstrainedForeignId('scenario_id');
            $t->dropConstrainedForeignId('created_by');
            $t->dropColumn('source');
        });
        Schema::dropIfExists('assortment_scenarios');
    }
};
