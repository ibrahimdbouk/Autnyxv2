<?php

namespace App\Services\DataQuality;

/**
 * W9 (WP9.5) — personal data in retail files.
 *
 * POS and loyalty exports often carry customer e-mails, phone numbers and
 * sometimes card numbers. Detection needs none of them. This finds the
 * columns that hold them (from the values, not only the header) and masks
 * them wherever raw rows are kept: the upload sample, quarantine and the
 * failed-row ledger. A customer reference that is an e-mail / phone / card
 * is replaced by a stable pseudonym, so per-customer analysis still works.
 *
 * Columns mapped to a field that legitimately holds contact details (a
 * user's e-mail, a supplier's phone — config data_quality.pii.contact_fields)
 * are left alone. Card numbers never are.
 */
final class PiiGuard
{
    public const KIND_EMAIL = 'email';
    public const KIND_PHONE = 'phone';
    public const KIND_CARD  = 'card';
    public const KIND_ID    = 'national_id';

    private const EMAIL = '/^[^@\s]{1,64}@[^@\s]+\.[a-z]{2,}$/i';
    private const PHONE_HEADER = '/(phone|mobile|tel\b|telephone|whats\s*app|msisdn|gsm)/i';
    private const ID_HEADER = '/(passport|national[\s_-]*id|emirates[\s_-]*id|\bssn\b|\biban\b|card[\s_-]*(no|num|number)|\bpan\b|cc[\s_-]*num)/i';

    public static function enabled(): bool
    {
        return (bool) config('data_quality.pii.mask', true);
    }

    /**
     * Columns of these rows that hold personal data.
     *
     * @param  array<int,array<string,mixed>>  $rows     raw rows keyed by source header
     * @param  array<string,?string>           $targets  source header → mapped field (null = unmapped)
     * @return array<string,string>  header → kind
     */
    public static function detect(array $rows, array $targets = []): array
    {
        if ($rows === []) {
            return [];
        }
        $contact = config('data_quality.pii.contact_fields', []);
        $headers = array_keys($rows[0] + array_merge(...array_map(fn ($r) => (array) $r, array_slice($rows, 1, 5))));
        $out = [];

        foreach ($headers as $h) {
            $values = [];
            foreach ($rows as $r) {
                $v = trim((string) ($r[$h] ?? ''));
                if ($v !== '') {
                    $values[] = $v;
                }
                if (count($values) >= 200) {
                    break;
                }
            }
            $kind = self::classify((string) $h, $values);
            if ($kind === null) {
                continue;
            }
            $target = $targets[$h] ?? null;
            if ($kind !== self::KIND_CARD && $target !== null && in_array($target, $contact, true)) {
                continue;   // a contact field of a user / supplier / store — that is the point of the column
            }
            $out[(string) $h] = $kind;
        }

        return $out;
    }

    /** @param array<int,string> $values */
    public static function classify(string $header, array $values): ?string
    {
        if (preg_match(self::ID_HEADER, $header)) {
            return str_contains(strtolower($header), 'card') || preg_match('/\b(pan|cc)\b/i', $header) ? self::KIND_CARD : self::KIND_ID;
        }
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        $share = fn (callable $test) => count(array_filter($values, $test)) / $n;

        if ($share(fn ($v) => (bool) preg_match(self::EMAIL, $v)) >= 0.5) {
            return self::KIND_EMAIL;
        }
        // Card numbers: 13–19 digits that pass the Luhn check. A barcode column
        // passes Luhn about one time in ten, so most values must.
        if ($share(fn ($v) => self::looksLikeCard($v)) >= 0.8) {
            return self::KIND_CARD;
        }
        $phoneHeader = (bool) preg_match(self::PHONE_HEADER, $header);
        if ($share(fn ($v) => self::looksLikePhone($v, $phoneHeader)) >= 0.6) {
            return self::KIND_PHONE;
        }

        return null;
    }

    public static function looksLikeCard(string $v): bool
    {
        if (! preg_match('/^\d[\d -]{11,22}\d$/', $v)) {
            return false;
        }
        $digits = preg_replace('/\D/', '', $v);
        $len = strlen($digits);
        if ($len < 13 || $len > 19) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < $len; $i++) {
            $d = (int) $digits[$len - 1 - $i];
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return $sum % 10 === 0;
    }

    /** "+971 50 123 4567", "(050) 123-4567"; bare digits only under a phone-like header. */
    public static function looksLikePhone(string $v, bool $phoneHeader): bool
    {
        if (! preg_match('/^\+?[\d\s().-]{7,22}$/', $v) || preg_match('/[.,]\d{1,2}$/', $v)) {
            return false;   // not phone-shaped, or a decimal amount
        }
        $digits = strlen(preg_replace('/\D/', '', $v));
        if ($digits < 8 || $digits > 15) {
            return false;
        }
        if (str_starts_with($v, '+') || str_starts_with($v, '00')) {
            return true;
        }

        return $phoneHeader || ($digits >= 9 && preg_match('/\d[\s()-]+\d/', $v) === 1);
    }

    public static function mask(mixed $value, string $kind): mixed
    {
        $v = trim((string) $value);
        if ($v === '') {
            return $value;
        }

        return match ($kind) {
            self::KIND_EMAIL => (function () use ($v) {
                [$user, $domain] = array_pad(explode('@', $v, 2), 2, '');
                $tld = str_contains($domain, '.') ? substr($domain, strrpos($domain, '.')) : '';

                return mb_substr($user, 0, 1) . '***@' . mb_substr($domain, 0, 1) . '***' . $tld;
            })(),
            self::KIND_CARD, self::KIND_PHONE => '***' . substr(preg_replace('/\D/', '', $v), -4),
            default => '***' . mb_substr($v, -2),
        };
    }

    /**
     * @param  array<string,mixed>   $row
     * @param  array<string,string>  $columns  key → kind
     */
    public static function maskRow(array $row, array $columns): array
    {
        foreach ($columns as $key => $kind) {
            if (array_key_exists($key, $row)) {
                $row[$key] = self::mask($row[$key], $kind);
            }
        }

        return $row;
    }

    /** Mask a sample of rows on the values alone (before any mapping exists). */
    public static function maskSample(array $rows): array
    {
        if (! self::enabled() || $rows === []) {
            return $rows;
        }
        $cols = self::detect(array_values(array_filter($rows, 'is_array')));

        return $cols === [] ? $rows : array_map(fn ($r) => is_array($r) ? self::maskRow($r, $cols) : $r, $rows);
    }

    /** A stable, tenant-scoped pseudonym for a customer reference that is personal data. */
    public static function pseudonym(int $tenantId, string $value): string
    {
        $norm = mb_strtolower(preg_replace('/[\s().-]/', '', trim($value)));

        return 'cust_' . substr(hash_hmac('sha256', $tenantId . '|' . $norm, (string) config('app.key')), 0, 16);
    }

    /** Is this single value personal data (for a customer reference)? */
    public static function isPersonal(string $value): bool
    {
        $v = trim($value);

        return $v !== '' && (preg_match(self::EMAIL, $v) || self::looksLikeCard($v) || self::looksLikePhone($v, false));
    }
}
