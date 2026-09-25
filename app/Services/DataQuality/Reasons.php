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
    // W9: feed-level and personal-data warnings (recorded on the batch).
    public const FEED_VOLUME_LOW   = 'feed_volume_low';
    public const FEED_VOLUME_HIGH  = 'feed_volume_high';
    public const FEED_PARTIAL      = 'feed_partial';
    public const FEED_SCHEMA_DRIFT = 'feed_schema_drift';
    public const FEED_MISSING_COLUMNS = 'feed_missing_columns';
    public const PII_DETECTED      = 'pii_detected';

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
        self::FEED_VOLUME_LOW   => 'Fewer rows than this feed usually sends',
        self::FEED_VOLUME_HIGH  => 'More rows than this feed usually sends',
        self::FEED_PARTIAL      => 'Partial delivery suspected (held)',
        self::FEED_SCHEMA_DRIFT => 'Columns changed since the last batch',
        self::FEED_MISSING_COLUMNS => 'Required columns missing',
        self::PII_DETECTED      => 'Personal data found (masked)',
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
        self::FEED_VOLUME_LOW   => 'medium',
        self::FEED_VOLUME_HIGH  => 'low',
        self::FEED_PARTIAL      => 'high',
        self::FEED_SCHEMA_DRIFT => 'medium',
        self::FEED_MISSING_COLUMNS => 'high',
        self::PII_DETECTED      => 'medium',
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
