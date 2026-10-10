<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.1 — prove and show.
 *
 *   assortment_plans.holdouts   similar stores held back from the plan's category
 *                               while it is measured: [{store_id, name, why}]
 *   assortment_briefings        the weekly assortment briefing per tenant: what the
 *                               rules picked (content) and, when AI is on, the
 *                               sentences it wrote from them (narrative)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assortment_plans', function (Blueprint $t) {
            $t->json('holdouts')->nullable();
        });

        Schema::create('assortment_briefings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->date('week_of');                      // the Monday it covers the week before
            $t->json('content');                      // rule-built: headline, sections, facts
            $t->json('narrative')->nullable();        // AI: headline + summary, checked against the facts
            $t->string('narrated_model', 80)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'week_of']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assortment_briefings');
        Schema::table('assortment_plans', function (Blueprint $t) {
            $t->dropColumn('holdouts');
        });
    }
};
