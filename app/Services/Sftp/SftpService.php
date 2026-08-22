<?php

namespace App\Services\Sftp;

use App\Models\SftpConnection;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * SftpService — builds an on-demand SFTP filesystem for a connection and tests
 * connectivity. Requires league/flysystem-sftp-v3 (Laravel's 'sftp' driver).
 */
class SftpService
{
    public function disk(SftpConnection $connection): Filesystem
    {
        return Storage::build($connection->diskConfig());
    }

    /**
     * Attempt to connect and list the base path.
     *
     * @return array{ok:bool, message:string}
     */
    public function testConnection(SftpConnection $connection): array
    {
        try {
            $disk = $this->disk($connection);
            // Any listing operation forces a real connection.
            $disk->files('.');
            return ['ok' => true, 'message' => 'Connection successful.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->cleanError($e->getMessage())];
        }
    }

    public function cleanError(string $message): string
    {
        // Keep it short and avoid leaking full stack detail in the UI.
        return \Illuminate\Support\Str::limit(trim($message), 240);
    }
}
