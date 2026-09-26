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
    // WP3.4 — values that carry a unit and are converted to the column's unit.
    public const PERCENT = 'percent'; // tax_rate: "5%" or 0.05 → 5
    public const WEIGHT  = 'weight';  // → grams
    public const LENGTH  = 'length';  // → millimetres
    public const VOLUME  = 'volume';  // → cm³

    private const MAP = [
        // dates
        'date' => self::DATE, 'as_of_date' => self::DATE, 'order_date' => self::DATE,
        'expected_date' => self::DATE, 'received_date' => self::DATE, 'generated_at' => self::DATE,
        'target_date' => self::DATE, 'start_date' => self::DATE, 'end_date' => self::DATE,
        // numbers
        'quantity' => self::NUMBER, 'unit_price' => self::NUMBER, 'total_amount' => self::NUMBER,
        'discount' => self::NUMBER, 'on_hand_qty' => self::NUMBER, 'reorder_point' => self::NUMBER,
        'on_order_qty' => self::NUMBER, 'inventory_value' => self::NUMBER, 'unit_cost' => self::NUMBER,
        'selling_price' => self::NUMBER, 'qty_ordered' => self::NUMBER, 'qty_received' => self::NUMBER,
        'open_qty' => self::NUMBER, 'fill_rate' => self::NUMBER, 'value' => self::NUMBER,
        'late_days' => self::INT, 'lead_time_days' => self::INT, 'line_no' => self::INT,
        // WP3.4 — hardening fields
        'cost_amount' => self::NUMBER, 'safety_stock' => self::NUMBER, 'allocated_qty' => self::NUMBER,
        'in_transit_qty' => self::NUMBER, 'sales_area_sqm' => self::NUMBER, 'min_order_value' => self::NUMBER,
        'latitude' => self::NUMBER, 'longitude' => self::NUMBER, 'geofence_radius_m' => self::INT, 'promo_price' => self::NUMBER, 'units_per_case' => self::NUMBER,
        'discount_pct' => self::PERCENT,
        'expiry_date' => self::DATE, 'opened_on' => self::DATE,
        'tax_rate' => self::PERCENT, 'weight_grams' => self::WEIGHT, 'volume_cm3' => self::VOLUME,
        'length_mm' => self::LENGTH, 'width_mm' => self::LENGTH, 'height_mm' => self::LENGTH,
        // identity / codes
        'sku' => self::KEY,
        'po_number' => self::CODE, 'transaction_id' => self::CODE, 'return_id' => self::CODE,
        'barcode' => self::CODE, 'code' => self::CODE, 'supplier_code' => self::CODE,
        'gtin' => self::CODE, 'postal_code' => self::CODE, 'currency' => self::CODE, 'customer_ref' => self::CODE,
        'promotion_ref' => self::CODE, 'waste_ref' => self::CODE, 'batch_ref' => self::CODE, 'original_transaction_ref' => self::CODE,
        // email
        'email' => self::EMAIL, 'contact_email' => self::EMAIL,
    ];

    /** Fields treated as entity NAMES (trim + collapse whitespace, alias-resolvable). */
    private const NAMES = [
        'name', 'product_name', 'location', 'supplier', 'category', 'subcategory',
        'brand', 'city', 'region', 'country', 'address', 'format', 'type',
        'specialization', 'reason', 'payment_method', 'pack_size', 'role',
        'department', 'banner', 'channel', 'buyer', 'season', 'condition', 'promotion_name', 'mechanic', 'stores',
        'area', 'manages',
    ];

    /** Types whose canonical form is a plain number (after any unit conversion). */
    public static function isNumeric(string $type): bool
    {
        return in_array($type, [self::NUMBER, self::INT, self::PERCENT, self::WEIGHT, self::LENGTH, self::VOLUME], true);
    }

    public static function of(string $field): string
    {
        if (isset(self::MAP[$field])) {
            return self::MAP[$field];
        }

        return in_array($field, self::NAMES, true) ? self::NAME : self::TEXT;
    }
}
