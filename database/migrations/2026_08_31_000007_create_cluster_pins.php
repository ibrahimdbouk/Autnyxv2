<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — the manual overlay (Platform\Intelligence). Replaces the coarse
 * tenant-level "customised" freeze.
 *
 * A pin records an explicit human decision, stored SEPARATELY from the computed
 * clustering. Every rebuild recomputes fresh from the strategy, then re-applies
 * pins on top — so untouched stores keep getting fresh clustering, new stores are
 * auto-placed, and human intent survives the algorithm changing underneath.
 *
 * Two kinds:
 *  - membership: store_id is pinned into the cluster with key = target_key.
 *  - rename:     the cluster with key = target_key carries this label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cluster_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('objective')->default('general');
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('pin_type');    // membership | rename
            $table->string('target_key');  // cluster key the pin applies to
            $table->string('label')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'objective']);
            $table->index(['tenant_id', 'objective', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_pins');
    }
};
