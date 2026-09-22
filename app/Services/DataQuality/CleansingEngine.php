<?php

namespace App\Services\DataQuality;

use App\Services\Import\CanonicalSchema;
use Carbon\Carbon;

/**
 * Deterministic, per-field cleansing. Given a mapped row (canonical field keys),
 * applies: base string hygiene (trim, collapse whitespace, strip control chars,
 * Unicode NFC, common mojibake) → type-specific normalisation (dates → ISO, numbers
 * stripped of currency/separators, keys canonicalised) → tenant value-maps → tenant
 * rule overrides → entity-alias resolution. Every change is reflected in the returned
 * `changed` flag. Nothing here rejects a row — that is the validator's job.
 *
 * $ctx (built once per import by the firewall):
 *   aliases    => ['sku'=>[alias=>canonical], 'store'=>[…], 'supplier'=>[…]]
 *   value_maps => [field => [from_lower => to]]
 *   rules      => [field => [ ['rule_type'=>…, 'params'=>[…]], … ] ]   (ordinal-sorted)
 *   upper_keys => bool,  strip_zeros => bool
 *
 * See claude/data-quality-firewall.md.
 */
class CleansingEngine
{
    private const MOJIBAKE = [
        'â€™' => "'", 'â€˜' => "'", 'â€œ' => '"', 'â€' => '"', 'â€“' => '-',
        'â€”' => '-', 'Â' => '', 'â€¦' => '…',
    ];

    /** @return array{data: array<string,mixed>, changed: bool} */
    public function clean(string $dataType, array $data, array $ctx): array
    {
        $fields  = array_keys(CanonicalSchema::forType($dataType));
        $changed = false;

        foreach ($fields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $original = $data[$field];
            if ($original === null) {
                continue;
            }

            $value = $this->baseClean((string) $original);
            $value = $this->byType($dataType, $field, $value, $ctx);

            // Value dictionary (content normalisation), case-insensitive.
            $map = $ctx['value_maps'][$field] ?? null;
            if ($map && $value !== '' && isset($map[mb_strtolower($value)])) {
                $value = $map[mb_strtolower($value)];
            }

            // Tenant rule overrides (ordinal order).
            foreach ($ctx['rules'][$field] ?? [] as $rule) {
                $value = $this->applyRule($value, $rule['rule_type'] ?? '', $rule['params'] ?? []);
            }

            if ((string) $original !== (string) $value) {
                $changed = true;
            }
            $data[$field] = $value === '' ? null : $value;
        }

        return ['data' => $data, 'changed' => $changed];
    }

    // ── Base hygiene ───────────────────────────────────────────────────────────

    private function baseClean(string $v): string
    {
        $v = strtr($v, self::MOJIBAKE);
        // strip control chars (keep tab→space handled by whitespace collapse)
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? $v;
        // normalise Unicode spaces (NBSP, thin/zero-width, ideographic) to a plain space
        $v = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u', ' ', $v) ?? $v;
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;   // collapse whitespace
        $v = trim($v);
        if (class_exists(\Normalizer::class) && $v !== '') {
            $n = \Normalizer::normalize($v, \Normalizer::FORM_C);
            if (is_string($n)) {
                $v = $n;
            }
        }
        return $v;
    }

    // ── Type-specific ──────────────────────────────────────────────────────────

    private function byType(string $dataType, string $field, string $value, array $ctx): string
    {
        if ($value === '') {
            return $value;
        }

        switch (FieldTypes::of($field)) {
            case FieldTypes::DATE:
                return $this->toIsoDate($value) ?? $value;

            case FieldTypes::NUMBER:
            case FieldTypes::INT:
                return $this->toNumber($value) ?? $value;

            case FieldTypes::KEY:
                $value = str_replace(' ', '', $value);
                if (! empty($ctx['upper_keys'])) {
                    $value = mb_strtoupper($value);
                }
                if (! empty($ctx['strip_zeros'])) {
                    $stripped = ltrim($value, '0');
                    $value = $stripped === '' ? '0' : $stripped;
                }
                return $this->alias($ctx, 'sku', $value);

            case FieldTypes::CODE:
                return mb_strtoupper($value);

            case FieldTypes::EMAIL:
                return mb_strtolower($value);

            case FieldTypes::NAME:
                return $this->aliasName($dataType, $field, $value, $ctx);

            default:
                return $value;
        }
    }

    private function aliasName(string $dataType, string $field, string $value, array $ctx): string
    {
        $type = match (true) {
            $field === 'location' => 'store',
            $field === 'supplier' => 'supplier',
            $field === 'name' && $dataType === 'stores'    => 'store',
            $field === 'name' && $dataType === 'suppliers' => 'supplier',
            default => null,
        };

        return $type ? $this->alias($ctx, $type, $value) : $value;
    }

    private function alias(array $ctx, string $entityType, string $value): string
    {
        $map = $ctx['aliases'][$entityType] ?? [];
        // aliases are stored normalised (lower); match case-insensitively
        return $map[mb_strtolower($value)] ?? $value;
    }

    // ── Parsers ────────────────────────────────────────────────────────────────

    private function toIsoDate(string $v): ?string
    {
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Strip currency & thousands separators, handle parentheses-negatives; null if not numeric. */
    private function toNumber(string $v): ?string
    {
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $v, $m)) {
            $neg = true;
            $v = $m[1];
        }
        // keep digits, separators, sign
        $v = preg_replace('/[^0-9.,\-]/', '', $v) ?? $v;
        if ($v === '' || $v === '-' || $v === '.') {
            return null;
        }

        $hasComma = str_contains($v, ',');
        $hasDot   = str_contains($v, '.');
        if ($hasComma && $hasDot) {
            $v = str_replace(',', '', $v);                       // comma = thousands
        } elseif ($hasComma) {
            // one comma with ≤2 trailing digits → decimal comma; else thousands
            $v = (preg_match('/^-?\d+,\d{1,2}$/', $v))
                ? str_replace(',', '.', $v)
                : str_replace(',', '', $v);
        }

        if (! is_numeric($v)) {
            return null;
        }

        return $neg ? (string) (-1 * (float) $v) : $v;
    }

    // ── Tenant rule application ─────────────────────────────────────────────────

    private function applyRule(string $value, string $type, array $params): string
    {
        return match ($type) {
            'trim'                => trim($value),
            'collapse_ws'         => trim(preg_replace('/\s+/u', ' ', $value) ?? $value),
            'upper'               => mb_strtoupper($value),
            'lower'               => mb_strtolower($value),
            'strip_leading_zeros' => ($s = ltrim($value, '0')) === '' ? ($value === '' ? '' : '0') : $s,
            'date_iso'            => $this->toIsoDate($value) ?? $value,
            'number'              => $this->toNumber($value) ?? $value,
            'default_if_blank'    => $value === '' ? (string) ($params['value'] ?? '') : $value,
            'regex_replace'       => @preg_replace('/' . str_replace('/', '\/', (string) ($params['pattern'] ?? '')) . '/u', (string) ($params['replacement'] ?? ''), $value) ?? $value,
            'value_map'           => (mb_strtolower($value) === mb_strtolower((string) ($params['from'] ?? '__none__'))) ? (string) ($params['to'] ?? $value) : $value,
            default               => $value,
        };
    }
}
