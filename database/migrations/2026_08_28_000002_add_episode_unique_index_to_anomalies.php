<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recovery Measurement — R2b: one episode per (subject, sequence).
 *
 * Renumbers any legacy duplicate `episode_seq` within a (tenant_id,
 * identity_key) group (from the pre-R2 era when SKU-less rules didn't dedup, or
 * a resolved episode plus a fresh recurrence both defaulted to seq 1), then adds
 * the strict unique index. Going forward the model's `creating` hook assigns the
 * next episode_seq at insert, so the constraint always holds.
 *
 * Safe on an empty table (tests) — the renumber is a no-op. NULL identity_key
 * rows never collide (Postgres treats NULLs as distinct in a unique index) and
 * are skipped by the renumber.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                UPDATE anomalies a
                SET episode_seq = s.rn
                FROM (
                    SELECT id,
                           ROW_NUMBER() OVER (
                               PARTITION BY tenant_id, identity_key
                               ORDER BY COALESCE(first_seen_at, detected_at, created_at), id
                           ) AS rn
                    FROM anomalies
                    WHERE identity_key IS NOT NULL
                ) s
                WHERE a.id = s.id
                  AND a.episode_seq IS DISTINCT FROM s.rn
            SQL);
        }

        Schema::table('anomalies', function (Blueprint $table) {
            $table->unique(['tenant_id', 'identity_key', 'episode_seq'], 'anomalies_episode_unique');
        });
    }

    public function down(): void
    {
        Schema::table('anomalies', function (Blueprint $table) {
            $table->dropUnique('anomalies_episode_unique');
        });
    }
};
