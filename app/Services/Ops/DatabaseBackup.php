<?php

namespace App\Services\Ops;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * WP8.1 (audit L13) — logical backups of the application database, kept in
 * object storage outside the database cluster, and the restore that proves
 * them.
 *
 * A backup is `pg_dump --format=custom` of the whole database, written to a
 * temporary file, uploaded as `<path>/<db>-<UTC stamp>.dump`, with a
 * `.json` manifest beside it (size, sha256, duration, server version, the
 * last migration, per-table row estimates). Credentials reach pg_dump through
 * its environment (PGPASSWORD), never its command line or the log.
 */
class DatabaseBackup
{
    public const STAMP = 'Ymd-His';

    /** @return array{path:string, bytes:int, sha256:string, seconds:float, tables:int} */
    public function run(): array
    {
        $conn = $this->connection();
        $tmp  = tempnam(sys_get_temp_dir(), 'autnyx-dump-');
        $at   = now('UTC');
        $t0   = microtime(true);

        try {
            $this->process([config('backup.pg_dump'), '--format=custom', '--compress=6', '--file=' . $tmp], $conn)->mustRun();
            $seconds = round(microtime(true) - $t0, 1);

            $name = $this->prefix($conn['database']) . $at->format(self::STAMP) . 'Z.dump';
            $path = trim(config('backup.path'), '/') . '/' . $name;
            $in   = fopen($tmp, 'rb');
            $ok   = $this->disk()->writeStream($path, $in);
            if (is_resource($in)) {
                fclose($in);
            }
            if (! $ok) {
                throw new \RuntimeException("Could not write the backup to {$path}.");
            }

            $manifest = [
                'path'           => $path,
                'created_at'     => $at->toIso8601String(),
                'bytes'          => filesize($tmp),
                'sha256'         => hash_file('sha256', $tmp),
                'seconds'        => $seconds,
                'server_version' => (string) DB::selectOne('SHOW server_version')->server_version,
                'last_migration' => DB::table('migrations')->orderByDesc('id')->value('migration'),
                'tables'         => $this->tableEstimates(),
            ];
            $this->disk()->put(substr($path, 0, -5) . '.json', json_encode($manifest, JSON_PRETTY_PRINT));

            return ['path' => $path, 'bytes' => $manifest['bytes'], 'sha256' => $manifest['sha256'],
                'seconds' => $seconds, 'tables' => count($manifest['tables'])];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Stored backups, newest first.
     *
     * @return array<int,array{path:string, at:Carbon, bytes:int}>
     */
    public function list(): array
    {
        $disk = $this->disk();
        $out  = [];
        foreach ($disk->files(trim(config('backup.path'), '/')) as $file) {
            if (! str_ends_with($file, '.dump') || ! preg_match('/-(\d{8}-\d{6})Z\.dump$/', $file, $m)) {
                continue;
            }
            $out[] = ['path' => $file, 'at' => Carbon::createFromFormat(self::STAMP, $m[1], 'UTC'), 'bytes' => (int) $disk->size($file)];
        }
        usort($out, fn ($a, $b) => $b['at'] <=> $a['at']);

        return $out;
    }

    public function latest(): ?array
    {
        return $this->list()[0] ?? null;
    }

    /**
     * Keep every backup of the last keep_daily days, then the newest of each
     * ISO week for keep_weekly weeks; delete the rest (with their manifests).
     *
     * @return array<int,string> deleted paths
     */
    public function prune(?Carbon $now = null): array
    {
        $now     = ($now ?? now('UTC'))->copy();
        $daily   = $now->copy()->subDays((int) config('backup.keep_daily', 14));
        $weekly  = $now->copy()->subWeeks((int) config('backup.keep_weekly', 8));
        $weeks   = [];
        $deleted = [];

        foreach ($this->list() as $b) {   // newest first
            $keep = $b['at']->gte($daily);
            if (! $keep && $b['at']->gte($weekly)) {
                $week = $b['at']->format('o-W');
                $keep = ! isset($weeks[$week]);
                $weeks[$week] = true;
            }
            if (! $keep) {
                $this->disk()->delete([$b['path'], substr($b['path'], 0, -5) . '.json']);
                $deleted[] = $b['path'];
            }
        }

        return $deleted;
    }

    /**
     * Restore a stored backup into a new, empty database on the same server
     * and compare it with the live one. Used by db:restore-drill; the scratch
     * database is dropped afterwards unless $keep.
     *
     * @return array<string,mixed>
     */
    public function restoreDrill(?string $path = null, bool $keep = false): array
    {
        $backup = $path ? ['path' => $path] : $this->latest();
        if (! $backup) {
            throw new \RuntimeException('There is no stored backup to restore.');
        }

        $conn    = $this->connection();
        $scratch = $conn['database'] . '_drill_' . now('UTC')->format('Ymd_His');
        $tmp     = tempnam(sys_get_temp_dir(), 'autnyx-restore-');
        $report  = ['backup' => $backup['path'], 'scratch_database' => $scratch];

        try {
            $t0 = microtime(true);
            $in = $this->disk()->readStream($backup['path']);
            $out = fopen($tmp, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($out);
            if (is_resource($in)) {
                fclose($in);
            }
            $report['download_seconds'] = round(microtime(true) - $t0, 1);
            $report['bytes'] = filesize($tmp);

            $manifest = json_decode((string) $this->disk()->get(substr($backup['path'], 0, -5) . '.json'), true) ?: [];
            $report['checksum_ok'] = ! isset($manifest['sha256']) || hash_file('sha256', $tmp) === $manifest['sha256'];
            if (! $report['checksum_ok']) {
                throw new \RuntimeException('The backup does not match its manifest checksum.');
            }

            $this->admin()->statement('CREATE DATABASE ' . $this->ident($scratch));
            $t1 = microtime(true);
            $this->process([config('backup.pg_restore'), '--no-owner', '--no-acl', '--exit-on-error',
                '--jobs=' . max(1, min(4, (int) (shell_exec('nproc') ?: 2))), '--dbname=' . $scratch, $tmp], $conn)->mustRun();
            $report['restore_seconds'] = round(microtime(true) - $t1, 1);

            $report['comparison'] = $this->compare($conn, $scratch);

            return $report;
        } finally {
            @unlink($tmp);
            if (! $keep) {
                try {
                    $this->admin()->statement('DROP DATABASE IF EXISTS ' . $this->ident($scratch) . ' WITH (FORCE)');
                    DB::disconnect('backup_admin');
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /**
     * Exact row counts of every public table, live against restored, plus the
     * migrations each carries. A restored count may trail the live one by
     * what was written after the backup was taken.
     */
    private function compare(array $conn, string $scratch): array
    {
        $live = $this->counts(DB::connection());

        config(['database.connections.backup_drill' => array_merge(config('database.connections.' . config('database.default')), ['database' => $scratch])]);
        DB::purge('backup_drill');
        try {
            $restored = $this->counts(DB::connection('backup_drill'));
            $migrationsLive = DB::table('migrations')->count();
            $migrationsRestored = DB::connection('backup_drill')->table('migrations')->count();
        } finally {
            DB::disconnect('backup_drill');
        }

        $missing = array_values(array_diff(array_keys($live), array_keys($restored)));
        $diffs   = [];
        foreach ($live as $table => $n) {
            if (isset($restored[$table]) && $restored[$table] !== $n) {
                $diffs[$table] = ['live' => $n, 'restored' => $restored[$table]];
            }
        }

        return [
            'tables_live'         => count($live),
            'tables_restored'     => count($restored),
            'missing_tables'      => $missing,
            'rows_live'           => array_sum($live),
            'rows_restored'       => array_sum($restored),
            'migrations_live'     => $migrationsLive,
            'migrations_restored' => $migrationsRestored,
            'count_differences'   => $diffs,
        ];
    }

    /** @return array<string,int> */
    private function counts($db): array
    {
        $out = [];
        foreach ($db->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename") as $t) {
            $out[$t->tablename] = (int) $db->selectOne('SELECT COUNT(*) AS n FROM ' . $this->ident($t->tablename))->n;
        }

        return $out;
    }

    /** @return array<string,int> planner row estimates (cheap), for the manifest */
    private function tableEstimates(): array
    {
        return collect(DB::select("SELECT c.relname, GREATEST(c.reltuples, 0)::bigint AS n FROM pg_class c
            JOIN pg_namespace ns ON ns.oid = c.relnamespace WHERE ns.nspname = 'public' AND c.relkind = 'r' ORDER BY c.relname"))
            ->mapWithKeys(fn ($r) => [$r->relname => (int) $r->n])->all();
    }

    private function process(array $cmd, array $conn): Process
    {
        $p = new Process($cmd, null, array_filter([
            'PGHOST'     => $conn['host'] ?? null,
            'PGPORT'     => (string) ($conn['port'] ?? '5432'),
            'PGDATABASE' => $conn['database'] ?? null,
            'PGUSER'     => $conn['username'] ?? null,
            'PGPASSWORD' => $conn['password'] ?? null,
            'PGSSLMODE'  => $conn['sslmode'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
        $p->setTimeout((int) config('backup.timeout', 3600));

        return $p;
    }

    /** A connection of its own: CREATE / DROP DATABASE can't run inside a transaction. */
    private function admin()
    {
        config(['database.connections.backup_admin' => config('database.connections.' . config('database.default'))]);

        return DB::connection('backup_admin');
    }

    private function connection(): array
    {
        $conn = config('database.connections.' . config('database.default'));
        if (($conn['driver'] ?? null) !== 'pgsql') {
            throw new \RuntimeException('Backups support PostgreSQL only.');
        }

        return $conn;
    }

    private function prefix(string $database): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $database) . '-';
    }

    private function ident(string $name): string
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("Unsafe identifier: {$name}");
        }

        return '"' . $name . '"';
    }

    private function disk()
    {
        return Storage::disk(config('backup.disk'));
    }
}
