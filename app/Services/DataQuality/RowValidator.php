<?php

namespace App\Services\DataQuality;

use App\Services\Import\CanonicalSchema;

/**
 * Validates a CLEANSED row. Returns a hard reject reason (→ quarantine) plus any soft
 * warnings (recorded, still promoted). Required-field presence, required date/number
 * parseability, and referential integrity (orphan SKU) live here. See
 * claude/data-quality-firewall.md.
 *
 * $ctx: ['product_skus' => array<string,true>, 'referential_gate' => bool]
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
        $warnings = [];

        // 1. Required presence — the key first, then the rest.
        if ($keyField !== null && $this->blank($data[$keyField] ?? null)) {
            return ['reason' => Reasons::MISSING_KEY, 'warnings' => $warnings];
        }
        foreach ($schema as $field => $def) {
            if (($def['required'] ?? false) && $this->blank($data[$field] ?? null)) {
                return ['reason' => Reasons::MISSING_REQUIRED, 'warnings' => $warnings];
            }
        }

        // 2. Required date / number parseability (post-cleanse: dates are ISO on success).
        foreach ($schema as $field => $def) {
            if (! ($def['required'] ?? false) || $this->blank($data[$field] ?? null)) {
                continue;
            }
            $value = (string) $data[$field];
            $type  = FieldTypes::of($field);
            if ($type === FieldTypes::DATE && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return ['reason' => Reasons::INVALID_DATE, 'warnings' => $warnings];
            }
            if (($type === FieldTypes::NUMBER || $type === FieldTypes::INT) && ! is_numeric($value)) {
                return ['reason' => Reasons::INVALID_NUMBER, 'warnings' => $warnings];
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
