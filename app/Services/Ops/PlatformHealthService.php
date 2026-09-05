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
        $expected = [
            'baselines:compute' => 26,
            'anomalies:detect'  => 26,
            'anomalies:notify'  => 26,
        ];

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

            $isStale = $lastOk === null
                || \Illuminate\Support\Carbon::parse($lastOk)->lt(now()->subHours($maxHours));

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

    /** Failed queue jobs (best-effort — the table may not exist on sync driver). */
    public function failedQueueJobs(): int
    {
        try {
            return Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;
        } catch (Throwable $e) {
            return 0;
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
            'stuck_imports'  => $imports['stuck'],
            'failed_imports' => $imports['failed'],
            'db_bytes'       => $this->database()['bytes'],
        ];
    }
}
