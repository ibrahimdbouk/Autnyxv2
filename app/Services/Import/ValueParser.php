<?php

namespace App\Services\Import;

use App\Models\Import;
use App\Services\DataQuality\FieldTypes;
use App\Models\Tenant;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * WP3.2 (audit C7, H26) — the one place a raw cell becomes a date or a number.
 *
 * Deterministic, never guesses:
 *  • Dates follow the import's configured order (tenant default d/m/Y, D4). A
 *    value that is invalid in that order is rejected, not silently swapped.
 *    With "auto", a value that could be either order (03/04/2026) is rejected
 *    as AMBIGUOUS. Year-first (2026-04-03), month names, compact YYYYMMDD,
 *    Excel serial numbers and OData /Date(ms)/ are unambiguous and always read.
 *  • Numbers follow the configured decimal separator. Currency codes/symbols,
 *    thousands separators (space, apostrophe, the other mark), (12.50) and
 *    12.50- negatives and scientific notation are handled; anything else with
 *    stray characters inside the number is rejected.
 *  • Blank means null/whitespace only — "0" is a value.
 */
final class ValueParser
{
    public const DATE_DMY  = 'd/m/Y';
    public const DATE_MDY  = 'm/d/Y';
    public const DATE_YMD  = 'Y-m-d';
    public const DATE_AUTO = 'auto';

    public const DATE_FORMATS = [
        self::DATE_DMY  => 'Day first — 31/12/2026',
        self::DATE_MDY  => 'Month first — 12/31/2026',
        self::DATE_YMD  => 'Year first — 2026-12-31',
        self::DATE_AUTO => 'Detect — reject dates that could be either order',
    ];

    public const DECIMALS = [
        '.' => 'Dot — 1,234.56',
        ',' => 'Comma — 1.234,56',
    ];

    public const DEFAULT_DATE_FORMAT = self::DATE_DMY;
    public const DEFAULT_DECIMAL     = '.';

    public const AMBIGUOUS      = 'ambiguous_date';
    public const INVALID        = 'invalid_date';
    public const INVALID_NUMBER = 'invalid_number';

    /** Row metadata: the row's dates/numbers are already canonical (ISO / dot). */
    public const META_CANONICAL = '__canonical';
    /** Row metadata: field => reason for cells that could not be read. */
    public const META_INVALID   = '__invalid';

    private const MONTHS = '/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\b/i';

    public readonly string $dateFormat;
    public readonly string $decimal;

    public function __construct(?string $dateFormat = null, ?string $decimal = null)
    {
        $this->dateFormat = array_key_exists((string) $dateFormat, self::DATE_FORMATS) ? $dateFormat : self::DEFAULT_DATE_FORMAT;
        $this->decimal    = array_key_exists((string) $decimal, self::DECIMALS) ? $decimal : self::DEFAULT_DECIMAL;
    }

    /** Import override → tenant default → platform default. */
    public static function forImport(Import $import): self
    {
        $settings = $import->tenant_id ? (Tenant::whereKey($import->tenant_id)->value('settings') ?? []) : [];
        if (is_string($settings)) {
            $settings = json_decode($settings, true) ?: [];
        }

        // Spreadsheet cells hold real numbers, which the reader always renders
        // with a dot — the decimal setting only applies to text files.
        $decimal = self::isSpreadsheet((string) ($import->original_filename ?: $import->path))
            ? '.'
            : ($import->decimal_separator ?: ($settings['import_decimal_separator'] ?? null));

        return new self($import->date_format ?: ($settings['import_date_format'] ?? null), $decimal);
    }

    public static function isSpreadsheet(string $filename): bool
    {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['xlsx', 'xls', 'xlsm', 'ods'], true);
    }

    public static function forTenant(?Tenant $tenant): self
    {
        $s = $tenant?->settings ?? [];

        return new self($s['import_date_format'] ?? null, $s['import_decimal_separator'] ?? null);
    }

    /** The parser for values that are already canonical (ISO dates, dot decimals). */
    public static function canonical(): self
    {
        return new self(self::DATE_YMD, '.');
    }

    /**
     * Convert every date / number field of a mapped row to its canonical form
     * ONCE, under this parser's rules. Unreadable cells keep their raw value
     * and are listed in META_INVALID with the reason. Idempotent.
     */
    public function normalizeRow(string $dataType, array $data): array
    {
        if (! empty($data[self::META_CANONICAL])) {
            return $data;
        }

        $invalid = [];
        foreach (array_keys(CanonicalSchema::forType($dataType)) as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $type = FieldTypes::of($field);
            if (self::isBlank($data[$field])) {
                if (in_array($type, [FieldTypes::DATE, FieldTypes::NUMBER, FieldTypes::INT], true)) {
                    $data[$field] = null;
                }
                continue;
            }
            if ($type === FieldTypes::DATE) {
                $r = $this->date($data[$field]);
                if ($r['value'] !== null) {
                    $data[$field] = $r['value'];
                } else {
                    $invalid[$field] = $r['reason'];
                }
            } elseif ($type === FieldTypes::NUMBER || $type === FieldTypes::INT) {
                $n = $this->number($data[$field]);
                if ($n !== null) {
                    $data[$field] = $n;
                } else {
                    $invalid[$field] = self::INVALID_NUMBER;
                }
            }
        }

        $data[self::META_CANONICAL] = true;
        $data[self::META_INVALID]   = $invalid;

        return $data;
    }

    /** The row without parsing metadata (for storage / display). */
    public static function stripMeta(array $data): array
    {
        unset($data[self::META_CANONICAL], $data[self::META_INVALID]);

        return $data;
    }

    public static function isBlank(mixed $v): bool
    {
        return $v === null || (is_string($v) && trim($v) === '');
    }

    // ── Dates ────────────────────────────────────────────────────────────────

    /**
     * @return array{value: ?string, reason: ?string}  ISO Y-m-d, or a reason
     *         (self::AMBIGUOUS / self::INVALID). Blank → both null.
     */
    public function date(mixed $raw): array
    {
        if (self::isBlank($raw)) {
            return ['value' => null, 'reason' => null];
        }
        $v = trim(preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', (string) $raw) ?? (string) $raw);

        // OData / SAP JSON: /Date(1693526400000)/ or /Date(1693526400000+0000)/
        if (preg_match('#^/?Date\((-?\d+)(?:[+-]\d{4})?\)/?$#', $v, $m)) {
            return $this->ok(Carbon::createFromTimestampMs((int) $m[1], 'UTC')->toDateString());
        }

        // Excel serial number (a date cell read without its format).
        if (preg_match('/^\d{5}(\.\d+)?$/', $v) && (float) $v >= 10000 && (float) $v < 100000) {
            return $this->ok(ExcelDate::excelToDateTimeObject((float) $v)->format('Y-m-d'));
        }

        // Compact YYYYMMDD.
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $m)) {
            return $this->ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // Year first — 2026-04-03, 2026/04/03, 2026.04.03 (optionally with a time).
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T].*)?$/', $v, $m)) {
            return $this->ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // Numeric day/month or month/day — the configured order decides.
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4}|\d{2})(?:[ T].*)?$/', $v, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            $y = $this->year($m[3]);

            return match ($this->dateFormat) {
                self::DATE_MDY => $this->ymd($y, $a, $b),
                self::DATE_DMY => $this->ymd($y, $b, $a),
                default        => $this->autoOrder($y, $a, $b), // auto, or year-first configured
            };
        }

        // Month names are unambiguous: "3 Apr 2026", "Apr 3, 2026", "03-Apr-26".
        if (preg_match(self::MONTHS, $v)) {
            try {
                return $this->ok(Carbon::parse($v)->toDateString());
            } catch (\Throwable) {
                return ['value' => null, 'reason' => self::INVALID];
            }
        }

        return ['value' => null, 'reason' => self::INVALID];
    }

    private function autoOrder(int $y, int $a, int $b): array
    {
        if ($a === $b || ($a > 12 && $b <= 12)) {
            return $this->ymd($y, $b, $a);
        }
        if ($b > 12 && $a <= 12) {
            return $this->ymd($y, $a, $b);
        }

        return ['value' => null, 'reason' => ($a <= 12 && $b <= 12) ? self::AMBIGUOUS : self::INVALID];
    }

    private function year(string $y): int
    {
        if (strlen($y) === 4) {
            return (int) $y;
        }

        return ((int) $y) < 70 ? 2000 + (int) $y : 1900 + (int) $y;
    }

    private function ymd(int $y, int $m, int $d): array
    {
        return checkdate($m, $d, $y)
            ? $this->ok(sprintf('%04d-%02d-%02d', $y, $m, $d))
            : ['value' => null, 'reason' => self::INVALID];
    }

    private function ok(string $iso): array
    {
        return ['value' => $iso, 'reason' => null];
    }

    /** Human description of the expected date shape, for error messages. */
    public function dateFormatLabel(): string
    {
        return self::DATE_FORMATS[$this->dateFormat];
    }

    // ── Numbers ──────────────────────────────────────────────────────────────

    /** Canonical numeric string ("-1234.5"), or null when blank / not a number. */
    public function number(mixed $raw): ?string
    {
        if (is_int($raw) || is_float($raw)) {
            return $this->plain((float) $raw);
        }
        if (self::isBlank($raw)) {
            return null;
        }

        $s = trim((string) $raw);
        $negative = false;

        if (preg_match('/^\((.*)\)$/u', $s, $m)) {          // (12.50) accounting negative
            $negative = true;
            $s = trim($m[1]);
        }

        // Thousands marks that are never decimals: spaces (incl. NBSP / thin), apostrophes.
        $s = preg_replace("/[\\s\\x{00A0}\\x{2009}\\x{202F}'’]/u", '', $s) ?? $s;

        [$s, $negative] = $this->takeSign($s, $negative);

        // Currency prefix / suffix ("AED", "$", "€", "د.إ", "SAR").
        $s = preg_replace('/^[^\d.,\-+]+/u', '', $s) ?? $s;
        [$s, $negative] = $this->takeSign($s, $negative);

        // Scientific notation (always dot-decimal in exports): 1.2E+3, 5e-2.
        if (preg_match('/^\d*\.?\d+[eE][+\-]?\d+$/', $s)) {
            return $this->signed($this->plain((float) $s), $negative);
        }

        $s = preg_replace('/[^\d.,\-]+$/u', '', $s) ?? $s;

        if (str_ends_with($s, '-')) {                        // SAP-style trailing minus: 12.50-
            $negative = ! $negative;
            $s = substr($s, 0, -1);
        }

        // Thousands marks must group by 3 and sit before the decimal mark;
        // anything else (1.234,56 read as dot-decimal) is a format mismatch.
        $dec = preg_quote($this->decimal, '/');
        $th  = preg_quote($this->decimal === ',' ? '.' : ',', '/');
        if (! preg_match('/^(\d{1,3}(?:' . $th . '\d{3})+|\d*)(?:' . $dec . '\d*)?$/', $s) || ! preg_match('/\d/', $s)) {
            return null;
        }
        $s = str_replace($this->decimal === ',' ? '.' : ',', '', $s);
        $s = str_replace($this->decimal, '.', $s);

        $s = rtrim(str_starts_with($s, '.') ? '0' . $s : $s, '.');
        $s = ltrim($s, '0');
        if ($s === '' || str_starts_with($s, '.')) {
            $s = '0' . $s;
        }

        return $this->signed($s, $negative);
    }

    /** @return array{0: string, 1: bool} */
    private function takeSign(string $s, bool $negative): array
    {
        if (str_starts_with($s, '-')) {
            return [substr($s, 1), ! $negative];
        }
        if (str_starts_with($s, '+')) {
            return [substr($s, 1), $negative];
        }

        return [$s, $negative];
    }

    private function signed(string $s, bool $negative): string
    {
        return $negative && (float) $s != 0.0 ? '-' . $s : $s;
    }

    private function plain(float $f): string
    {
        if (floor($f) == $f && abs($f) < 1e15) {
            return number_format($f, 0, '.', '');
        }
        $s = rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }
}
