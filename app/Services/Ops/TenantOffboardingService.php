<?php

namespace App\Services\Ops;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

/**
 * Tenant offboarding — data export (portability) and permanent erasure.
 *
 * Erasure relies on the schema's cascade: nearly every tenant_id foreign key is
 * declared cascadeOnDelete, so deleting the tenant row removes all tenant-scoped
 * data at the database level. The one exception is users.tenant_id (RESTRICT),
 * so users are deleted first. Everything runs in one transaction, so any failure
 * rolls back cleanly rather than leaving a half-deleted tenant.
 */
class TenantOffboardingService
{
    /**
     * Every table carrying a tenant_id, discovered from the live schema so new
     * tables are covered automatically.
     *
     * @return array<int,string>
     */
    public function tenantScopedTables(): array
    {
        return collect(Schema::getTables())
            ->pluck('name')
            ->filter(fn ($t) => Schema::hasColumn($t, 'tenant_id'))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The platform root tenant, or any tenant that holds the protected owner
     * account, must never be erased.
     */
    public function isProtected(Tenant $tenant): bool
    {
        if ($tenant->slug === 'autnyx') {
            return true;
        }

        $ownerEmail = strtolower(trim((string) config('autnyx.owner_email')));

        return $ownerEmail !== ''
            && User::where('tenant_id', $tenant->id)
                ->whereRaw('LOWER(email) = ?', [$ownerEmail])
                ->exists();
    }

    /**
     * Build a ZIP of every tenant-scoped row (one CSV per non-empty table) plus
     * the tenant record and a manifest. Returns the absolute path to a temp file
     * — the caller streams it and deletes it after send.
     */
    public function export(Tenant $tenant): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is not available on this server.');
        }

        $path = tempnam(sys_get_temp_dir(), 'tenant_export_') . '.zip';
        $zip  = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $manifest = [
            'tenant'       => ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug],
            'generated_at' => now()->toIso8601String(),
            'tables'       => [],
        ];

        $zip->addFromString('tenant.json', json_encode($tenant->toArray(), JSON_PRETTY_PRINT));

        foreach ($this->tenantScopedTables() as $table) {
            $rows = DB::table($table)->where('tenant_id', $tenant->id)->get();
            $manifest['tables'][$table] = $rows->count();

            if ($rows->isNotEmpty()) {
                $zip->addFromString($table . '.csv', $this->rowsToCsv($rows));
            }
        }

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $zip->close();

        return $path;
    }

    /**
     * Permanently delete a tenant and all of its data. Transaction-wrapped, so a
     * failure (e.g. an unexpected RESTRICT foreign key) rolls back with no
     * partial deletion.
     */
    public function erase(Tenant $tenant): void
    {
        if ($this->isProtected($tenant)) {
            throw new \RuntimeException('This tenant is protected and cannot be erased.');
        }

        DB::transaction(function () use ($tenant) {
            // users.tenant_id is RESTRICT — remove users before the tenant so the
            // tenant delete isn't blocked; the tenant delete cascades the rest.
            User::where('tenant_id', $tenant->id)->delete();
            Tenant::whereKey($tenant->id)->delete();
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int,object>  $rows
     */
    private function rowsToCsv($rows): string
    {
        $handle  = fopen('php://temp', 'r+');
        $headers = array_keys((array) $rows->first());
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            $line = [];
            foreach ((array) $row as $value) {
                $line[] = is_null($value) ? '' : (is_scalar($value) ? $value : json_encode($value));
            }
            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
