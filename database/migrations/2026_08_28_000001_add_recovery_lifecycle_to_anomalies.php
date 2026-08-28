<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recovery Measurement — R1: identity + lifecycle foundation.
 *
 * Additive only. Gives every anomaly a persistent per-rule identity (formalising
 * the existing M15 dedup key: tenant + rule_type + store_id + sku) and the
 * lifecycle columns the nightly reconciliation (R2) will advance. Reuses the
 * existing `resolved_at`. No new tables; no changes to detection logic.
 *
 * The (tenant_id, identity_key) index is deliberately NON-unique here: the
 * strict unique (tenant_id, identity_key, episode_seq) constraint lands in R2,
 * once the reconciliation job owns episode creation and any pre-existing dupes
 * have been numbered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anomalies', function (Blueprint $table) {
            // Persistent identity + episode sequence (recurrence handled in R2).
            $table->string('identity_key', 40)->nullable()->after('rule_type');
            $table->unsignedSmallInteger('episode_seq')->default(1)->after('identity_key');

            // Lifecycle state machine (advanced by the R2 reconciliation job).
            $table->string('lifecycle_state', 16)->default('open')->after('episode_seq'); // open|persisting|clearing|resolved
            $table->timestamp('first_seen_at')->nullable()->after('detected_at');
            $table->timestamp('last_seen_at')->nullable()->after('first_seen_at');
            $table->timestamp('cleared_at')->nullable()->after('last_seen_at');
            $table->unsignedSmallInteger('clear_streak')->default(0)->after('cleared_at');
            $table->unsignedInteger('occurrence_count')->default(1)->after('clear_streak');
            $table->foreignId('previous_episode_id')->nullable()->after('occurrence_count')
                ->constrained('anomalies')->nullOnDelete();

            // Value-at-risk frozen when the episode opens (later formula changes
            // must not retroactively rewrite recovery history).
            $table->decimal('value_at_open', 14, 2)->nullable()->after('previous_episode_id');

            // Backfilled episodes have estimated (not measured) history — flagged
            // so they can be excluded from / labelled in the headline recovery.
            $table->boolean('backfilled')->default(false)->after('value_at_open');

            $table->index(['tenant_id', 'identity_key'], 'anomalies_tenant_identity_idx');
            $table->index(['tenant_id', 'lifecycle_state'], 'anomalies_tenant_lifecycle_idx');
        });
    }

    public function down(): void
    {
        Schema::table('anomalies', function (Blueprint $table) {
            $table->dropForeign(['previous_episode_id']);
            $table->dropIndex('anomalies_tenant_identity_idx');
            $table->dropIndex('anomalies_tenant_lifecycle_idx');
            $table->dropColumn([
                'identity_key',
                'episode_seq',
                'lifecycle_state',
                'first_seen_at',
                'last_seen_at',
                'cleared_at',
                'clear_streak',
                'occurrence_count',
                'previous_episode_id',
                'value_at_open',
                'backfilled',
            ]);
        });
    }
};
