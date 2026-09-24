<?php

namespace App\Services\DataQuality;

/**
 * Classifies a canonical field (from CanonicalSchema) into a semantic type, so the
 * cleanser and validator know how to normalise and check it without per-field code.
 * See claude/data-quality-firewall.md.
 */
class FieldTypes
{
    public const DATE   = 'date';
    public const NUMBER = 'number';
    public const INT    = 'integer';
    public const KEY    = 'key';    // identity code — hard canonicalisation (sku)
    public const CODE   = 'code';   // reference code — light canonicalisation
    public const EMAIL  = 'email';
    public const NAME   = 'name';   // entity / free text — trim + collapse
    public const TEXT   = 'text';

    private const MAP = [
        // dates
        'date' => self::DATE, 'as_of_date' => self::DATE, 'order_date' => self::DATE,
        'expected_date' => self::DATE, 'received_date' => self::DATE, 'generated_at' => self::DATE,
        'target_date' => self::DATE,
        // numbers
        'quantity' => self::NUMBER, 'unit_price' => self::NUMBER, 'total_amount' => self::NUMBER,
        'discount' => self::NUMBER, 'on_hand_qty' => self::NUMBER, 'reorder_point' => self::NUMBER,
        'on_order_qty' => self::NUMBER, 'inventory_value' => self::NUMBER, 'unit_cost' => self::NUMBER,
        'selling_price' => self::NUMBER, 'qty_ordered' => self::NUMBER, 'qty_received' => self::NUMBER,
        'open_qty' => self::NUMBER, 'fill_rate' => self::NUMBER, 'value' => self::NUMBER,
        'late_days' => self::INT, 'lead_time_days' => self::INT, 'line_no' => self::INT,
        // identity / codes
        'sku' => self::KEY,
        'po_number' => self::CODE, 'transaction_id' => self::CODE, 'return_id' => self::CODE,
        'barcode' => self::CODE, 'code' => self::CODE, 'supplier_code' => self::CODE,
        // email
        'email' => self::EMAIL, 'contact_email' => self::EMAIL,
    ];

    /** Fields treated as entity NAMES (trim + collapse whitespace, alias-resolvable). */
    private const NAMES = [
        'name', 'product_name', 'location', 'supplier', 'category', 'subcategory',
        'brand', 'city', 'region', 'country', 'address', 'format', 'type',
        'specialization', 'reason', 'payment_method', 'pack_size', 'role',
    ];

    public static function of(string $field): string
    {
        if (isset(self::MAP[$field])) {
            return self::MAP[$field];
        }

        return in_array($field, self::NAMES, true) ? self::NAME : self::TEXT;
    }
}
