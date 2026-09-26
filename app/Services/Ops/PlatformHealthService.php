<?php

namespace App\Services\Ops;

use App\Models\Import;
use App\Models\JobRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ops — "is the platform healthy right now?" Scheduled-pipeline status, import
 * health, database size, and queue failures. Every query is defensive so the
 * page never errors on a permissions/edge issue.
 */
class PlatformHealthService
{
    private const STUCK_IMPORT_MINUTES = 15;

    /** Latest run per scheduled command (Postgres DISTINCT ON). */
    public function pipeline(): array
    {
        try {
            $rows = DB::select(
                'SELECT DISTINCT ON (command) command, status, ran_at, duration_ms
                 FROM job_runs ORDER BY command, ran_at DESC'
            );
        } catch (Throwable $e) {
            return [];
        }

        return collect($rows)->map(fn ($r) => [
            'command'     => $r->command,
            'status'      => $r->status,
            'ran_at'      => $r->ran_at,
            'duration_ms' => $r->duration_ms,
        ])->sortBy('command')->values()->all();
    }

    /** Recent failed scheduled runs. */
    public function recentFailures(int $limit = 15): array
    {
        return JobRun::where('status', JobRun::STATUS_FAILED)
            ->orderByDesc('ran_at')
            ->limit($limit)
            ->get(['command', 'message', 'ran_at'])
            ->all();
    }

    /** Import pipeline health across all tenants. */
    public function imports(): array
    {
        $stuck = Import::where('status', Import::STATUS_IMPORTING)
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_IMPORT_MINUTES))
            ->count();

        $byStatus = Import::query()
            ->selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        return [
            'stuck'          => $stuck,
            'failed'         => (int) ($byStatus[Import::STATUS_FAILED] ?? 0),
            'with_errors'    => (int) ($byStatus[Import::STATUS_COMPLETED_WITH_ERRORS] ?? 0),
            'awaiting_review'=> (int) ($byStatus[Import::STATUS_MAPPING_REVIEW] ?? 0),
            'importing'      => (int) ($byStatus[Import::STATUS_IMPORTING] ?? 0),
            'completed'      => (int) ($byStatus[Import::STATUS_COMPLETED] ?? 0),
        ];
    }

    /**
     * Critical daily commands whose latest SUCCESS is older than their SLA (or
     * that have never succeeded despite the pipeline having run). This is what
     * catches a nightly job silently not running — e.g. under hibernation.
     *
     * @return array<int,array{command:string,last_success:?string,max_hours:int}>
     */
    public function staleCommands(): array
    {
        // command => max hours since last success before it's considered stale.
        // WP5.2/5.3: the nightly work runs per tenant inside nightly:dispatch's
        // chains (see staleNights()); the dispatcher itself runs hourly.
        $expected = [
            'nightly:dispatch' => 3,
        ];
        // WP8.1: a missing nightly backup is an incident, not a footnote.
        if (config('backup.enabled')) {
            $expected['db:backup'] = (int) config('backup.max_age_hours', 30);
        }

        // Only judge staleness once the pipeline has run at least once (avoids a
        // brand-new environment reporting everything stale before its first night).
        try {
            if (! JobRun::query()->exists()) {
                return [];
            }
        } catch (Throwable $e) {
            return [];
        }

        $stale = [];
        foreach ($expected as $command => $maxHours) {
            $lastOk = JobRun::where('command', $command)
                ->where('status', JobRun::STATUS_SUCCESS)
                ->orderByDesc('ran_at')
                ->value('ran_at');

            // Never succeeded counts only once the platform has been recording
            // longer than the SLA (a new command isn't "stale" on its first deploy).
            $isStale = $lastOk === null
                ? JobRun::where('ran_at', '<', now()->subHours($maxHours))->exists()
                : \Illuminate\Support\Carbon::parse($lastOk)->lt(now()->subHours($maxHours));

            if ($isStale) {
                $stale[] = [
                    'command'      => $command,
                    'last_success' => $lastOk ? (string) $lastOk : null,
                    'max_hours'    => $maxHours,
                ];
            }
        }

        return $stale;
    }

    /** Failed queue jobs in the last $hours (WP5.3 — old failures are history, not an alert). */
    public function failedQueueJobs(int $hours = 24): int
    {
        try {
            return Schema::hasTable('failed_jobs')
                ? (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subHours($hours))->count()
                : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Minutes the oldest waiting queue job has been waiting (0 = none) — a stopped worker shows here first. */
    public function oldestQueuedMinutes(): int
    {
        try {
            $oldest = Schema::hasTable('jobs')
                ? DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', now()->timestamp)->min('available_at')
                : null;
        } catch (Throwable $e) {
            return 0;
        }

        return $oldest ? (int) floor((now()->timestamp - (int) $oldest) / 60) : 0;
    }

    /**
     * WP5.3 — active tenants whose last nightly chain did not finish cleanly in
     * the last 26 hours (failed, stuck, or never ran once the dispatcher has).
     *
     * @return array<int,array{tenant_id:int,name:string,status:?string,finished_at:?string}>
     */
    public function staleNights(): array
    {
        try {
            if (! Schema::hasTable('tenant_nightly_runs') || ! \App\Models\TenantNightlyRun::query()->exists()) {
                return [];
            }
            $out = [];
            foreach (\App\Models\Tenant::where('status', 'active')->get(['id', 'name']) as $t) {
                $last = \App\Models\TenantNightlyRun::where('tenant_id', $t->id)->orderByDesc('local_date')->first();
                $ok = $last && $last->status === \App\Models\TenantNightlyRun::STATUS_DONE
                    && $last->finished_at && $last->finished_at->gte(now()->subHours(26));
                if (! $ok) {
                    $out[] = ['tenant_id' => $t->id, 'name' => $t->name, 'status' => $last?->status,
                        'finished_at' => $last?->finished_at?->toDateTimeString()];
                }
            }

            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Database size + the biggest tables (Postgres). */
    public function database(): array
    {
        try {
            $bytes = (int) (DB::selectOne('SELECT pg_database_size(current_database()) AS b')->b ?? 0);

            $tables = collect(DB::select(
                "SELECT relname AS name, pg_total_relation_size(relid) AS bytes, n_live_tup AS rows
                 FROM pg_stat_user_tables ORDER BY pg_total_relation_size(relid) DESC LIMIT 10"
            ))->map(fn ($r) => [
                'name'  => $r->name,
                'bytes' => (int) $r->bytes,
                'rows'  => (int) $r->rows,
            ])->all();

            return ['bytes' => $bytes, 'tables' => $tables];
        } catch (Throwable $e) {
            return ['bytes' => 0, 'tables' => []];
        }
    }

    /**
     * The tables that grow with every day of trading, and the row count at
     * which one tenant's share should be split out (partitioned by date).
     */
    public const HOT_TABLES = ['sales_transactions', 'sales_daily', 'inventory_levels', 'purchase_orders', 'anomalies', 'sales_weekly'];

    /**
     * W13 — partitioning readiness. Postgres keeps single tables fast to tens
     * of millions of rows with the indexes in place; past that, one tenant's
     * history is better split by month. Planner estimates only (no count(*));
     * when a table is past the watch level, a 1% sample says which tenants
     * hold the rows.
     *
     * @return array<int, array{table:string, rows:int, level:string, tenants:array<int, array{tenant_id:int, rows:int}>}>
     *   level: watch (≥ 80% of the threshold) | partition (≥ threshold)
     */
    public function partitionReadiness(?int $threshold = null): array
    {
        $threshold ??= (int) config('autnyx.partition_rows', 50_000_000);
        $out = [];
        try {
            $est = collect(DB::select("SELECT relname, GREATEST(reltuples, 0)::bigint AS n FROM pg_class
                WHERE relkind IN ('r', 'p') AND relnamespace = 'public'::regnamespace AND relname IN ('" . implode("','", self::HOT_TABLES) . "')"))
                ->pluck('n', 'relname');
            foreach (self::HOT_TABLES as $table) {
                $rows = (int) ($est[$table] ?? 0);
                if ($rows < 0.8 * $threshold) {
                    continue;
                }
                $tenants = collect(DB::select("SELECT tenant_id, (COUNT(*) * 100)::bigint AS n FROM {$table} TABLESAMPLE SYSTEM (1)
                    GROUP BY tenant_id ORDER BY 2 DESC LIMIT 5"))
                    ->map(fn ($r) => ['tenant_id' => (int) $r->tenant_id, 'rows' => (int) $r->n])
                    ->filter(fn ($r) => $r['rows'] >= 0.8 * $threshold)->values()->all();
                $out[] = ['table' => $table, 'rows' => $rows, 'level' => $rows >= $threshold ? 'partition' : 'watch', 'tenants' => $tenants];
            }
        } catch (Throwable) {
            return [];
        }

        return $out;
    }

    /** Latest data:purge run. */
    public function lastPurge(): ?JobRun
    {
        return JobRun::where('command', 'data:purge')->orderByDesc('ran_at')->first();
    }

    /** One-line health verdict for the summary tiles. */
    public function summary(): array
    {
        $recentFailure = JobRun::where('status', JobRun::STATUS_FAILED)
            ->where('ran_at', '>=', now()->subDay())
            ->exists();

        $imports = $this->imports();
        $stale   = $this->staleCommands();

        return [
            'pipeline_ok'    => ! $recentFailure && empty($stale),
            'stale'          => $stale,
            'failed_jobs'    => $this->failedQueueJobs(),
            'queue_wait_min' => $this->oldestQueuedMinutes(),
            'stale_nights'   => $this->staleNights(),
            'stuck_imports'  => $imports['stuck'],
            'failed_imports' => $imports['failed'],
            'db_bytes'       => $this->database()['bytes'],
            'partition'      => $this->partitionReadiness(),
        ];
    }
}
