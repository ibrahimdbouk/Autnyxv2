<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W13 — the retail calendar: Ramadan, Eid al-Fitr, Eid al-Adha (Umm al-Qura
 * dates, computed), back to school, summer, Christmas & New Year, White
 * Friday, national days — and the tenant's own events. Each has a build-up
 * (lead) and an after-effect (tail), optional countries and categories.
 * Detection leaves alone a demand swing the calendar explains, the way it
 * does for promotions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retail_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('key', 60);                        // ramadan, eid_al_fitr, back_to_school, … or the tenant's own
            $t->unsignedSmallInteger('year');
            $t->string('name', 120);
            $t->string('kind', 20)->default('seasonal');  // religious | seasonal | commercial | national | custom
            $t->date('starts_on');
            $t->date('ends_on');
            $t->unsignedSmallInteger('lead_days')->default(0);
            $t->unsignedSmallInteger('tail_days')->default(0);
            $t->json('countries')->nullable();            // ISO codes; null = everywhere
            $t->json('categories')->nullable();           // departments / categories; null = every product
            $t->boolean('active')->default(true);
            $t->string('source', 10)->default('tenant');  // default | tenant
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'key', 'year']);
            $t->index(['tenant_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retail_events');
    }
};
