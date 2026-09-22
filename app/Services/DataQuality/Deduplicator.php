<?php

namespace App\Services\DataQuality;

use App\Services\Import\CanonicalSchema;

/**
 * Exact dedup within an import (content hash of the canonical fields) and a
 * whole-file re-upload fingerprint. Fuzzy / cross-import dedup is future work.
 * See claude/data-quality-firewall.md.
 */
class Deduplicator
{
    /** Stable hash of a cleansed row's canonical values — the exact-duplicate key. */
    public function rowHash(string $dataType, array $data): string
    {
        $fields = array_keys(CanonicalSchema::forType($dataType));
        $parts  = [];
        foreach ($fields as $f) {
            $parts[] = $f . '=' . mb_strtolower(trim((string) ($data[$f] ?? '')));
        }

        return hash('xxh128', implode('|', $parts));
    }

    /** Cheap whole-file fingerprint — same file re-uploaded → same fingerprint. */
    public function fileFingerprint(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $hash = @hash_file('sha256', $path);

        return $hash === false ? null : $hash;
    }
}
