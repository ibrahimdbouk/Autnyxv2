<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * WP2.2 (audit M6) — an encrypted JSON array that can still READ rows written
 * before encryption was switched on (plain JSON). Every write encrypts, and
 * `outbound:encrypt-secrets` re-saves legacy rows so nothing stays plaintext.
 */
class EncryptedArrayWithLegacy implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($value), true);
        } catch (DecryptException) {
            $decoded = json_decode((string) $value, true); // legacy plaintext row
        }

        return is_array($decoded) ? $decoded : null;
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString(json_encode(is_array($value) ? $value : (array) $value));
    }

    /** True when the raw column value is not yet encrypted. */
    public static function isPlaintext(?string $raw): bool
    {
        if ($raw === null || $raw === '') {
            return false;
        }
        try {
            Crypt::decryptString($raw);

            return false;
        } catch (DecryptException) {
            return true;
        }
    }
}
