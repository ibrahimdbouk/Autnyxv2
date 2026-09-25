<?php

namespace App\Services\Ops;

use App\Jobs\Ops\EraseTenantJob;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Storage\TenantStorage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Tenant offboarding (WP6.7, audit H7) — data export (portability) and
 * permanent erasure (GDPR / UAE PDPL).
 *
 * Erasure:
 *   1. requestErase(): the tenant is closed at once (status `erasing`: no
 *      sign-in, no API, no nightly run) and the request is audited at platform
 *      level; a queued EraseTenantJob does the deleting.
 *   2. eraseChunked(): every table with a tenant_id — including the ones with
 *      no foreign key (sku_baselines, sku_profiles, agent_runs,
 *      campaign_reviews, job_runs) — is emptied in bounded chunks, so no
 *      single transaction holds millions of row locks and a crash resumes
 *      where it stopped. The tenant's users' notifications and the tenant's
 *      files (its storage prefix and every import file) go too. The tenant row
 *      is deleted last.
 *   3. Completion is recorded in platform_audit_logs, which is not
 *      tenant-scoped and so survives the erasure.
 *
 * Export: one CSV per tenant table, written row by row from a cursor into a
 * temporary file, never holding a table in memory; credential columns are
 * never exported.
 */
class TenantOffboardingService
{
    /** Columns stripped from the offboarding export (WP2.2 / audit L1). */
    private const SECRET_COLUMNS = [
        'users'             => ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'],
        'api_keys'          => ['key_hash'],
        'api_connections'   => ['auth_config'],
        'outbound_targets'  => ['config'],
        'sftp_connections'  => ['password', 'private_key', 'private_key_passphrase'],
        'sso_connections'   => ['client_secret', 'domain_verification_token'],
        'teams_connections' => ['channel_webhook_url'],
    ];

    /** Emptied last, in this order (other tables may still point at them). */
    private const LAST = ['imports', 'stores', 'products', 'suppliers', 'users'];

    public function __construct(private TenantStorage $storage) {}

    /**
     * Every table carrying a tenant_id, discovered from the live schema so new
     * tables are covered automatically.
     *
     * @return array<int,string>
     */
    public function tenantScopedTables(): array
    {
        if (DB::getDriverName() === 'pgsql') {
            return collect(DB::select(
                "SELECT table_name FROM information_schema.columns
                  WHERE table_schema = current_schema() AND column_name = 'tenant_id' ORDER BY table_name"
            ))->pluck('table_name')->reject(fn ($t) => $t === 'platform_audit_logs')->values()->all();
        }

        return collect(Schema::getTables())->pluck('name')
            ->filter(fn ($t) => Schema::hasColumn($t, 'tenant_id'))->sort()->values()->all();
    }

    /** The platform root tenant, or any tenant holding the owner account, is never erased. */
    public function isProtected(Tenant $tenant): bool
    {
        if ($tenant->slug === 'autnyx') {
            return true;
        }

        // WP2.1: ownership is the immutable is_owner flag, not an email match.
        return User::where('tenant_id', $tenant->id)->where('is_owner', true)->exists();
    }

    // ── Erasure ──────────────────────────────────────────────────────────────

    /** Close the tenant now and queue the erase. */
    public function requestErase(Tenant $tenant, ?User $by = null): void
    {
        if ($this->isProtected($tenant)) {
            throw new \RuntimeException('This tenant is protected and cannot be erased.');
        }

        $tenant->forceFill(['status' => Tenant::STATUS_ERASING])->save();
        // Signed-in sessions of its users end now.
        DB::table('sessions')->whereIn('user_id', User::where('tenant_id', $tenant->id)->select('id'))->delete();

        PlatformAuditLog::record('tenant.erase_requested', $tenant, [
            'slug' => $tenant->slug, 'rows' => $this->rowCounts($tenant->id),
        ], $by);

        EraseTenantJob::dispatch($tenant->id);
    }

    /**
     * Delete up to $budgetSeconds worth of the tenant's data. Returns true
     * once the tenant is gone. Safe to call repeatedly (resumes).
     */
    public function eraseChunked(int $tenantId, int $budgetSeconds = 600, int $chunk = 5000): bool
    {
        $tenant = Tenant::find($tenantId);
        if ($tenant === null) {
            return true;
        }
        if ($this->isProtected($tenant)) {
            throw new \RuntimeException('This tenant is protected and cannot be erased.');
        }
        if ($tenant->status !== Tenant::STATUS_ERASING) {
            $tenant->forceFill(['status' => Tenant::STATUS_ERASING])->save();
        }

        $deadline = microtime(true) + $budgetSeconds;
        $started  = $tenant->settings['erase_started_at'] ?? null;
        if ($started === null) {
            $this->eraseFiles($tenantId);   // needs the imports rows, so first
            $settings = $tenant->settings ?? [];
            $settings['erase_started_at'] = now()->toIso8601String();
            $tenant->forceFill(['settings' => $settings])->save();
        }

        // Notifications are addressed to users (polymorphic, no foreign key).
        DB::table('notifications')->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', User::where('tenant_id', $tenantId)->select('id'))->delete();

        $tables = $this->eraseOrder();
        do {
            $progress = false;
            $blocked  = [];
            foreach ($tables as $table) {
                while (true) {
                    if (microtime(true) > $deadline) {
                        return false;
                    }
                    try {
                        $n = $this->deleteChunk($table, $tenantId, $chunk);
                    } catch (QueryException $e) {
                        // Still referenced by a row another table will lose later in this pass.
                        $blocked[$table] = $e->getMessage();
                        break;
                    }
                    if ($n === 0) {
                        break;
                    }
                    $progress = true;
                }
            }
            if ($blocked === []) {
                break;
            }
            if (! $progress) {
                throw new \RuntimeException('Tenant erase is stuck on: ' . implode('; ', array_map(
                    fn ($t, $m) => $t . ' (' . \Illuminate\Support\Str::limit($m, 160) . ')', array_keys($blocked), $blocked)));
            }
            $tables = array_keys($blocked);
        } while (true);

        $name = $tenant->name;
        $slug = $tenant->slug;
        Tenant::whereKey($tenantId)->delete();

        PlatformAuditLog::create([
            'event' => 'tenant.erased', 'subject_type' => 'Tenant', 'subject_id' => $tenantId,
            'subject_label' => $name, 'details' => ['slug' => $slug, 'started_at' => $started ?? now()->toIso8601String()],
        ]);
        Log::info('[offboarding] tenant erased', ['tenant_id' => $tenantId]);

        return true;
    }

    /** Erase synchronously (tests, load test, CLI). */
    public function eraseNow(int $tenantId): void
    {
        $guard = 0;
        while (! $this->eraseChunked($tenantId, 3600) && ++$guard < 100) {
            // resume
        }
    }

    /** Kept for callers of the old API: request + run now. */
    public function erase(Tenant $tenant): void
    {
        if ($this->isProtected($tenant)) {
            throw new \RuntimeException('This tenant is protected and cannot be erased.');
        }
        $tenant->forceFill(['status' => Tenant::STATUS_ERASING])->save();
        $this->eraseNow($tenant->id);
    }

    /** @return array<int,string> tenant tables, the most-referenced ones last */
    private function eraseOrder(): array
    {
        $tables = array_values(array_diff($this->tenantScopedTables(), self::LAST));

        return array_merge($tables, array_values(array_intersect(self::LAST, $this->tenantScopedTables())));
    }

    private function deleteChunk(string $table, int $tenantId, int $chunk): int
    {
        if (DB::getDriverName() === 'pgsql') {
            return DB::affectingStatement(
                "DELETE FROM {$table} WHERE ctid = ANY(ARRAY(SELECT ctid FROM {$table} WHERE tenant_id = ? LIMIT {$chunk}))",
                [$tenantId]
            );
        }

        return DB::table($table)->where('tenant_id', $tenantId)->limit($chunk)->delete();
    }

    /** The tenant's storage prefix plus every import file (older imports sit outside the prefix). */
    private function eraseFiles(int $tenantId): void
    {
        DB::table('imports')->where('tenant_id', $tenantId)->whereNotNull('path')
            ->select(['id', 'disk', 'path'])->orderBy('id')
            ->each(function ($i) {
                try {
                    Storage::disk($i->disk ?: 'local')->delete($i->path);
                } catch (\Throwable $e) {
                    Log::warning('[offboarding] import file not deleted', ['import_id' => $i->id, 'error' => $e->getMessage()]);
                }
            });

        foreach (array_unique([$this->storage->diskName(), 'local']) as $disk) {
            try {
                Storage::disk($disk)->deleteDirectory($this->storage->tenantPrefix($tenantId));
            } catch (\Throwable $e) {
                Log::warning('[offboarding] tenant prefix not deleted', ['disk' => $disk, 'error' => $e->getMessage()]);
            }
        }
    }

    /** @return array<string,int> non-empty tables and their row counts */
    public function rowCounts(int $tenantId): array
    {
        $out = [];
        foreach ($this->tenantScopedTables() as $table) {
            $n = DB::table($table)->where('tenant_id', $tenantId)->count();
            if ($n > 0) {
                $out[$table] = $n;
            }
        }

        return $out;
    }

    // ── Export ───────────────────────────────────────────────────────────────

    /**
     * Build a ZIP of every tenant-scoped row (one CSV per non-empty table) plus
     * the tenant record and a manifest, streamed table by table through temp
     * files. Returns the absolute path of the ZIP; the caller removes it.
     */
    public function export(Tenant $tenant, ?User $by = null): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is not available on this server.');
        }

        $path = tempnam(sys_get_temp_dir(), 'tenant_export_') . '.zip';
        $zip  = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $temps = [];

        $manifest = [
            'tenant'       => ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug],
            'generated_at' => now()->toIso8601String(),
            'tables'       => [],
        ];
        $zip->addFromString('tenant.json', json_encode($tenant->toArray(), JSON_PRETTY_PRINT));

        foreach ($this->tenantScopedTables() as $table) {
            $strip = array_flip(self::SECRET_COLUMNS[$table] ?? []);
            $file  = tempnam(sys_get_temp_dir(), 'tenant_export_' . $table . '_');
            $fh    = fopen($file, 'w');
            $count = 0;
            $order = Schema::hasColumn($table, 'id') ? 'id' : null;

            $query = DB::table($table)->where('tenant_id', $tenant->id);
            $rows  = $order ? $query->orderBy($order)->lazy(2000) : $query->cursor();
            foreach ($rows as $row) {
                $row = array_diff_key((array) $row, $strip);
                if ($count === 0) {
                    fputcsv($fh, array_keys($row), escape: '');
                }
                fputcsv($fh, array_map(fn ($v) => is_null($v) ? '' : (is_scalar($v) ? $v : json_encode($v)), array_values($row)), escape: '');
                $count++;
            }
            fclose($fh);

            $manifest['tables'][$table] = $count;
            if ($count > 0) {
                $zip->addFile($file, $table . '.csv');
                $temps[] = $file;
            } else {
                @unlink($file);
            }
        }

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $zip->close();
        foreach ($temps as $f) {
            @unlink($f);
        }

        PlatformAuditLog::record('tenant.exported', $tenant, ['tables' => array_filter($manifest['tables'])], $by);

        return $path;
    }
}
