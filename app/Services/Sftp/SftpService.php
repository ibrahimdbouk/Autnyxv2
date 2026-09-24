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
    public function __construct(
        private readonly \App\Support\Http\EgressGuard $egress,
        private readonly SftpHostKeyProbe $probe,
    ) {
    }

    /**
     * WP2.2: never connect to an internal address (SSRF), and pin the server's
     * host key on first use so a man-in-the-middle can't harvest credentials
     * later (a changed key fails the connection until an admin re-trusts it).
     */
    public function disk(SftpConnection $connection): Filesystem
    {
        $this->egress->assertPublicHost((string) $connection->host);

        if (empty($connection->host_key_fingerprint)) {
            $fingerprint = $this->probe->fingerprint((string) $connection->host, (int) ($connection->port ?: 22));
            if ($fingerprint === null) {
                throw new \RuntimeException('Could not read the SFTP server host key.');
            }
            $connection->forceFill(['host_key_fingerprint' => $fingerprint])->save();
        }

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
