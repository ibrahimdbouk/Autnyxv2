<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.2–v2.4 — new tables only.
 *
 *   assortment_constraints   v2.2 commercial deals: keep a line, a brand or supplier floor
 *                            (fewest products in a category), per store, format or chain,
 *                            until a date; with what keeping it cost in the last run
 *   assortment_new_ranges    v2.3 a starting range for a new store, or a remodel, built
 *                            from similar stores and fitted to metres (a proposal only)
 *   assortment_programmes    v2.3 a chain-wide tail clean-up: one set of products to remove
 *                            wherever the engine proposes it, followed store by store
 *   assortment_groupings     v2.4 demand-based store groups, versioned: proposed, approved
 *                            by an admin (then used as peer groups), rejected or retired
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assortment_constraints', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 16);                       // keep | brand_min | supplier_min
            $t->string('sku', 100)->nullable();
            $t->string('brand', 160)->nullable();
            $t->string('supplier', 160)->nullable();
            $t->string('category', 160)->nullable();
            $t->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $t->string('format', 80)->nullable();         // a store format slug; null with no store = every store
            $t->unsignedInteger('min_count')->nullable();
            $t->date('until')->nullable();
            $t->string('note', 240)->nullable();
            $t->json('cost')->nullable();                 // {low, high, decisions, as_of} from the last run
            $t->timestamps();

            $t->index(['tenant_id', 'kind']);
        });

        Schema::create('assortment_new_ranges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name', 160);
            $t->string('kind', 12);                       // new | remodel
            $t->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $t->string('format', 80)->nullable();
            $t->string('region', 120)->nullable();
            $t->json('donors');                           // the similar stores it is built from
            $t->json('metres')->nullable();               // category => metres asked for
            $t->json('result');                           // per category: products, counts, metres, value ranges
            $t->date('as_of_date')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('assortment_programmes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name', 160);
            $t->string('status', 12);                     // proposed | accepted | done | cancelled
            $t->json('skus');
            $t->json('gap_ids')->nullable();              // the decisions it accepted
            $t->json('value')->nullable();                // {low, high} a year, at proposal
            $t->decimal('metres', 10, 2)->nullable();     // shelf freed, where shelf files exist
            $t->unsignedInteger('stores')->default(0);
            $t->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('due_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });

        Schema::create('assortment_groupings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->string('status', 12);                     // proposed | approved | rejected | superseded | retired
            $t->string('method', 24);
            $t->json('groups');                           // key => {label, members, profile}
            $t->json('assignment');                       // store id => group key
            $t->json('comparison');                       // against what was in force: stores moved, sizes
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assortment_groupings');
        Schema::dropIfExists('assortment_programmes');
        Schema::dropIfExists('assortment_new_ranges');
        Schema::dropIfExists('assortment_constraints');
    }
};
