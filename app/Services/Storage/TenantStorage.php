<?php

namespace App\Services\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 3a — private, tenant-isolated file storage.
 *
 * Every tenant's files live under `tenant/{id}/…` on the configured secure disk
 * (config/autnyx.php › storage_disk — local by default, S3 when provisioned).
 * Reads and writes are guarded so a path can never escape its tenant's prefix,
 * and files are always written private. `localPath()` yields a readable local
 * filesystem path for any backend (downloading from a remote disk to a temp file
 * when needed), so the rest of the app can keep reading files by path whether
 * they sit on local disk or S3.
 */
class TenantStorage
{
    public const CATEGORY_IMPORTS = 'imports';
    public const CATEGORY_EXPORTS = 'exports';

    /** The configured secure disk name. */
    public function diskName(): string
    {
        return (string) config('autnyx.storage_disk', 'local');
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /** `tenant/{id}` — the root prefix every tenant file must live under. */
    public function tenantPrefix(int $tenantId): string
    {
        return 'tenant/' . $tenantId;
    }

    /**
     * Store a stream under `tenant/{id}/{category}/{ulid}.{ext}` (private) and
     * return the stored path. The filename is a fresh ULID, so an uploaded
     * filename can never be used to traverse or collide.
     *
     * @param  resource  $stream
     */
    public function putStream(int $tenantId, string $category, $stream, ?string $extension = null): string
    {
        $category = $this->safeSegment($category);
        $name = (string) Str::ulid();
        if ($extension) {
            $name .= '.' . ltrim($this->safeSegment($extension), '.');
        }

        $path = $this->tenantPrefix($tenantId) . '/' . $category . '/' . $name;

        $this->disk()->writeStream($path, $stream, ['visibility' => 'private']);

        return $path;
    }

    /**
     * Guard: a path handed to us for a tenant MUST live under that tenant's
     * prefix. Blocks both cross-tenant access and `..` traversal.
     */
    public function assertWithin(int $tenantId, string $path): void
    {
        $prefix = $this->tenantPrefix($tenantId) . '/';
        $normalised = ltrim($path, '/');

        if (str_contains($normalised, '..') || ! str_starts_with($normalised, $prefix)) {
            throw new RuntimeException('Refusing cross-tenant file access.');
        }
    }

    public function exists(int $tenantId, string $path): bool
    {
        $this->assertWithin($tenantId, $path);

        return $this->disk()->exists($path);
    }

    public function delete(int $tenantId, string $path): void
    {
        $this->assertWithin($tenantId, $path);
        $this->disk()->delete($path);
    }

    /**
     * A short-lived signed URL for the object, when the backend supports it
     * (S3). Local disks don't produce temporary URLs — callers fall back to a
     * tenant-checked streaming download route.
     */
    public function temporaryUrl(int $tenantId, string $path, int $minutes = 5): ?string
    {
        $this->assertWithin($tenantId, $path);

        try {
            return $this->disk()->temporaryUrl($path, now()->addMinutes($minutes));
        } catch (\Throwable $e) {
            return null; // local driver / unsupported
        }
    }

    /**
     * Return a readable LOCAL filesystem path for a file on ANY disk. For a local
     * driver this is the file itself; for a remote driver (S3) the object is
     * streamed down to a temp file. Lets existing path-based readers (CSV/Excel)
     * work regardless of where the file actually lives.
     */
    public function localPath(string $diskName, string $path): string
    {
        if (config("filesystems.disks.{$diskName}.driver") === 'local') {
            return Storage::disk($diskName)->path($path);
        }

        $stream = Storage::disk($diskName)->readStream($path);
        if ($stream === null) {
            throw new RuntimeException("Unable to read {$path} from disk [{$diskName}].");
        }

        $tmp = tempnam(sys_get_temp_dir(), 'autnyx_');
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $tmp;
    }

    /** Allow only a safe path segment (no slashes, dots, traversal). */
    private function safeSegment(string $segment): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '', $segment) ?: 'file';
    }
}
