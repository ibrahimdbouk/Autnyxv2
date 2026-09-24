<?php

namespace App\Services\DataQuality;

/**
 * Typed reason codes for quarantine and quality warnings. Hard reasons reject a row
 * (it never reaches the canonical tables); warn_* reasons are recorded but still
 * promote. See claude/data-quality-firewall.md.
 */
class Reasons
{
    // ── Hard (reject → quarantine) ──────────────────────────────────────────
    public const MISSING_KEY      = 'missing_key';       // required identity absent (sku / po_number / email…)
    public const MISSING_REQUIRED = 'missing_required';  // other required field blank
    public const INVALID_DATE     = 'invalid_date';      // required date won't parse
    public const AMBIGUOUS_DATE   = 'ambiguous_date';    // WP3.2: could be day/month or month/day — never guessed
    public const INVALID_NUMBER   = 'invalid_number';    // required number won't parse
    public const DUPLICATE_ROW    = 'duplicate_row';     // exact duplicate within this import
    public const ORPHAN_REFERENCE = 'orphan_reference';  // SKU not in product master (only hard when the gate is on)

    // ── Warnings (recorded, still promote) ──────────────────────────────────
    public const WARN_ORPHAN_SKU  = 'warn_orphan_sku';
    public const WARN_INVALID_VALUE = 'warn_invalid_value'; // WP3.4: optional value unusable → stored empty

    public const LABELS = [
        self::MISSING_KEY      => 'Missing identity key',
        self::MISSING_REQUIRED => 'Missing required field',
        self::INVALID_DATE     => 'Unparseable date',
        self::AMBIGUOUS_DATE   => 'Ambiguous date (day/month order)',
        self::INVALID_NUMBER   => 'Unparseable number',
        self::DUPLICATE_ROW    => 'Duplicate row',
        self::ORPHAN_REFERENCE => 'Orphan SKU (no product master)',
        self::WARN_ORPHAN_SKU  => 'Orphan SKU (promoted, unmatched)',
        self::WARN_INVALID_VALUE => 'Invalid optional value (stored empty)',
    ];

    public const SEVERITY = [
        self::MISSING_KEY      => 'high',
        self::MISSING_REQUIRED => 'medium',
        self::INVALID_DATE     => 'medium',
        self::AMBIGUOUS_DATE   => 'medium',
        self::INVALID_NUMBER   => 'medium',
        self::DUPLICATE_ROW    => 'medium',
        self::ORPHAN_REFERENCE => 'high',
        self::WARN_ORPHAN_SKU  => 'low',
        self::WARN_INVALID_VALUE => 'low',
    ];

    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? $code;
    }

    public static function severity(string $code): string
    {
        return self::SEVERITY[$code] ?? 'medium';
    }
}
