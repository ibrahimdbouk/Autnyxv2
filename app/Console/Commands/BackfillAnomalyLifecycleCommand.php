<?php

namespace App\Console\Commands;

use App\Models\Anomaly;
use App\Services\Recovery\AnomalyIdentity;
use Illuminate\Console\Command;

/**
 * Recovery Measurement — R1: one-time backfill.
 *
 * Gives existing anomalies (created before the lifecycle columns existed) a
 * persistent identity and sensible first-seen / lifecycle values, so they don't
 * all read as brand new on the first reconciliation run.
 *
 * Every touched row is flagged `backfilled = true`: its history is ESTIMATED,
 * not measured, so downstream recovery metrics (R3) can exclude or separately
 * label it until genuinely-tracked history accrues.
 *
 * Idempotent: by default only rows without an identity yet are touched, so
 * re-running is a no-op. `--force` re-derives every row.
 */
class BackfillAnomalyLifecycleCommand extends Command
{
    protected $signature = 'autnyx:backfill-anomaly-lifecycle {--force : Re-derive every anomaly, not just un-backfilled ones}';

    protected $description = 'One-time backfill of persistent identity + lifecycle foundation onto existing anomalies (flagged backfilled).';

    public function handle(): int
    {
        $query = Anomaly::query();
        if (! $this->option('force')) {
            $query->whereNull('identity_key');
        }

        $count = 0;

        $query->orderBy('id')->chunkById(500, function ($rows) use (&$count): void {
            foreach ($rows as $anomaly) {
                /** @var Anomaly $anomaly */
                $anomaly->identity_key    = AnomalyIdentity::forAnomaly($anomaly);
                $anomaly->first_seen_at   = $anomaly->first_seen_at ?: ($anomaly->detected_at ?: $anomaly->created_at ?: now());
                $anomaly->last_seen_at    = $anomaly->last_seen_at ?: ($anomaly->detected_at ?: $anomaly->first_seen_at);
                $anomaly->episode_seq     = $anomaly->episode_seq ?: 1;
                $anomaly->occurrence_count = $anomaly->occurrence_count ?: 1;
                $anomaly->value_at_open   = $anomaly->value_at_open ?? self::frozenValue($anomaly);
                $anomaly->lifecycle_state = self::deriveState($anomaly);
                $anomaly->backfilled      = true;
                $anomaly->save();

                $count++;
            }
        });

        $this->info("Backfilled {$count} anomalies.");

        return Command::SUCCESS;
    }

    /** Value-at-open, frozen from the estimate the rule recorded (context.revenue_impact). */
    private static function frozenValue(Anomaly $anomaly): ?float
    {
        $ctx = $anomaly->context;
        $impact = is_array($ctx) ? ($ctx['revenue_impact'] ?? null) : null;

        return $impact !== null ? (float) $impact : null;
    }

    /**
     * Conservative state for a pre-existing row: a resolved anomaly maps to
     * resolved; everything else starts open and is advanced to persisting /
     * clearing by real reconciliation runs (R2). We do not invent clear streaks.
     */
    private static function deriveState(Anomaly $anomaly): string
    {
        return $anomaly->resolved_at !== null
            ? Anomaly::LIFECYCLE_RESOLVED
            : Anomaly::LIFECYCLE_OPEN;
    }
}
