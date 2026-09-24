<?php

namespace App\Services\Sftp;

/**
 * WP2.2 — read an SFTP server's public host key and fingerprint it the way
 * Flysystem verifies it ("sha256:<base64>"). Swappable in tests.
 */
class SftpHostKeyProbe
{
    public function fingerprint(string $host, int $port): ?string
    {
        $ssh = new \phpseclib3\Net\SSH2($host, $port, 10);
        $key = $ssh->getServerPublicHostKey();
        $ssh->disconnect();

        if (! is_string($key) || ! str_contains($key, ' ')) {
            return null;
        }
        $binary = base64_decode(explode(' ', $key, 3)[1], true);

        return $binary === false ? null : 'sha256:' . rtrim(base64_encode(hash('sha256', $binary, true)), '=');
    }
}
