<?php

namespace App\Services\DataQuality;

use App\Services\Import\CanonicalSchema;
use App\Services\Import\ValueParser;
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
        // WP3.2: a row normalised by the importer already has canonical dates /
        // numbers (unreadable ones are listed, raw, in its META_INVALID).
        $ctx['canonical_row'] = ! empty($data[ValueParser::META_CANONICAL]);

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
                $value = $this->applyRule($value, $rule['rule_type'] ?? '', $rule['params'] ?? [], ! empty($ctx['canonical_row']) ? ValueParser::canonical() : $this->parser($ctx));
            }

            if ((string) $original !== (string) $value) {
                $changed = true;
            }
            $data[$field] = $value === '' ? null : $value;
        }

        return ['data' => $data, 'changed' => $changed];
    }

    /** Apply a single transform to one value — used by the dry-run preview. */
    public function applyRuleType(string $value, string $ruleType, array $params = []): string
    {
        return $this->applyRule($this->baseClean($value), $ruleType, $params, new ValueParser());
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
            // WP3.2 (audit C7/H26): the import's date order and decimal mark
            // decide — an unreadable or ambiguous value is left as-is for the
            // validator to reject, never guessed.
            case FieldTypes::DATE:
                return ! empty($ctx['canonical_row']) ? $value : ($this->parser($ctx)->date($value)['value'] ?? $value);

            case FieldTypes::NUMBER:
            case FieldTypes::INT:
                return ! empty($ctx['canonical_row']) ? $value : ($this->parser($ctx)->number($value) ?? $value);

            case FieldTypes::PERCENT:
            case FieldTypes::WEIGHT:
            case FieldTypes::LENGTH:
            case FieldTypes::VOLUME:
                return ! empty($ctx['canonical_row']) ? $value : ($this->parser($ctx)->byUnitType(FieldTypes::of($field), $value) ?? $value);

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

    private function parser(array $ctx): ValueParser
    {
        return $ctx['parser'] ?? new ValueParser();
    }

    // ── Tenant rule application ─────────────────────────────────────────────────

    private function applyRule(string $value, string $type, array $params, ValueParser $parser): string
    {
        return match ($type) {
            'trim'                => trim($value),
            'collapse_ws'         => trim(preg_replace('/\s+/u', ' ', $value) ?? $value),
            'upper'               => mb_strtoupper($value),
            'lower'               => mb_strtolower($value),
            'strip_leading_zeros' => ($s = ltrim($value, '0')) === '' ? ($value === '' ? '' : '0') : $s,
            'date_iso'            => $parser->date($value)['value'] ?? $value,
            'number'              => $parser->number($value) ?? $value,
            'default_if_blank'    => $value === '' ? (string) ($params['value'] ?? '') : $value,
            'regex_replace'       => @preg_replace('/' . str_replace('/', '\/', (string) ($params['pattern'] ?? '')) . '/u', (string) ($params['replacement'] ?? ''), $value) ?? $value,
            'value_map'           => (mb_strtolower($value) === mb_strtolower((string) ($params['from'] ?? '__none__'))) ? (string) ($params['to'] ?? $value) : $value,
            default               => $value,
        };
    }
}
