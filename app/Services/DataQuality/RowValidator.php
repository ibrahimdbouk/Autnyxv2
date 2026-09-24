<?php

namespace App\Services\DataQuality;

use App\Services\Import\CanonicalSchema;

/**
 * Validates a CLEANSED row. Returns a hard reject reason (→ quarantine) plus any soft
 * warnings (recorded, still promoted). Required-field presence, required date/number
 * parseability, and referential integrity (orphan SKU) live here. See
 * claude/data-quality-firewall.md.
 *
 * $ctx: ['product_skus' => array<string,true>, 'referential_gate' => bool, 'strict' => bool]
 *
 * A missing identity key is ALWAYS hard (a keyless row is unusable). When `strict`
 * is off (default, behaviour-safe) missing-required and unparseable-type rows are
 * returned as warnings and still promote — the existing writers coerce them (blank
 * on-hand → 0) or throw to the failed-row ledger exactly as before. `strict` flips
 * them to hard quarantine reasons (Phase 2).
 */
class RowValidator
{
    /** The identity field whose absence is a MISSING_KEY (not just MISSING_REQUIRED). */
    private const KEY_FIELD = [
        'sales_transactions' => 'sku',
        'inventory_levels'   => 'sku',
        'products'           => 'sku',
        'returns'            => 'sku',
        'purchase_orders'    => 'po_number',
        'stores'             => 'name',
        'suppliers'          => 'name',
        'users'              => 'email',
    ];

    private const REFERENTIAL_TYPES = ['sales_transactions', 'inventory_levels', 'returns', 'purchase_orders'];

    /** @return array{reason: ?string, warnings: array<int,string>} */
    public function check(string $dataType, array $data, array $ctx): array
    {
        $schema   = CanonicalSchema::forType($dataType);
        $keyField = self::KEY_FIELD[$dataType] ?? null;
        $strict   = ! empty($ctx['strict']);
        $warnings = [];

        // 1. Required presence — the key first (ALWAYS hard), then the rest.
        if ($keyField !== null && $this->blank($data[$keyField] ?? null)) {
            return ['reason' => Reasons::MISSING_KEY, 'warnings' => $warnings];
        }
        foreach ($schema as $field => $def) {
            if (($def['required'] ?? false) && $field !== $keyField && $this->blank($data[$field] ?? null)) {
                if ($strict) {
                    return ['reason' => Reasons::MISSING_REQUIRED, 'warnings' => $warnings];
                }
                $warnings[] = Reasons::MISSING_REQUIRED; // let the writer coerce or fail to the ledger
            }
        }

        // 1b. WP3.2 (audit C7): a date that could be day/month OR month/day is
        //     never guessed — any date field, required or not, always hard.
        $invalid = $data[\App\Services\Import\ValueParser::META_INVALID] ?? [];
        if (in_array(\App\Services\Import\ValueParser::AMBIGUOUS, $invalid, true)) {
            return ['reason' => Reasons::AMBIGUOUS_DATE, 'warnings' => $warnings];
        }

        // 2. Required date / number parseability (post-cleanse: dates are ISO on success).
        foreach ($schema as $field => $def) {
            if (! ($def['required'] ?? false) || $this->blank($data[$field] ?? null)) {
                continue;
            }
            $value = (string) $data[$field];
            $type  = FieldTypes::of($field);
            if ($type === FieldTypes::DATE && (isset($invalid[$field]) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value))) {
                if ($strict) {
                    return ['reason' => Reasons::INVALID_DATE, 'warnings' => $warnings];
                }
                $warnings[] = Reasons::INVALID_DATE;
            }
            if (($type === FieldTypes::NUMBER || $type === FieldTypes::INT) && (isset($invalid[$field]) || ! is_numeric($value))) {
                if ($strict) {
                    return ['reason' => Reasons::INVALID_NUMBER, 'warnings' => $warnings];
                }
                $warnings[] = Reasons::INVALID_NUMBER;
            }
        }

        // 3. Referential integrity — SKU must resolve to the product master.
        if (in_array($dataType, self::REFERENTIAL_TYPES, true) && ! empty($ctx['product_skus'])) {
            $sku = trim((string) ($data['sku'] ?? ''));
            if ($sku !== '' && ! isset($ctx['product_skus'][$sku])) {
                if (! empty($ctx['referential_gate'])) {
                    return ['reason' => Reasons::ORPHAN_REFERENCE, 'warnings' => $warnings];
                }
                $warnings[] = Reasons::WARN_ORPHAN_SKU;
            }
        }

        return ['reason' => null, 'warnings' => $warnings];
    }

    private function blank($v): bool
    {
        return $v === null || (is_string($v) && trim($v) === '') || $v === '';
    }
}
