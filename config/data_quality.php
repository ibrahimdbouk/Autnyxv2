<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data Quality Firewall
    |--------------------------------------------------------------------------
    | The firewall cleanses and validates every mapped row before it reaches the
    | canonical tables detection reads. Bad rows are quarantined (not written).
    | Ships enabled but CONSERVATIVE: only structural garbage is hard-gated;
    | referential/quality issues are recorded as warnings and still promote,
    | unless `referential_gate` is turned on. See claude/data-quality-firewall.md.
    */

    // Master switch. Off → the pre-firewall behaviour (map → write) exactly.
    'enabled' => (bool) env('DATA_QUALITY_ENABLED', true),

    // Hard-gate a row whose SKU does not resolve to the product master. Default
    // OFF, because retailers routinely load sales before a complete product
    // master — an orphan SKU is recorded as a quality warning and still promotes.
    'referential_gate' => (bool) env('DATA_QUALITY_REFERENTIAL_GATE', false),

    // Strict validation (Phase 2 "flip the gate"). When OFF (default, behaviour-safe),
    // the firewall only quarantines a row with NO identity key; missing-required /
    // unparseable-type / exact-duplicate rows are recorded as warnings and still flow
    // to the existing writers (which coerce, e.g. blank on-hand → 0, or fail to the
    // failed-row ledger — exactly as before the firewall). When ON, those become hard
    // quarantine gates. See claude/data-quality-firewall.md.
    'strict_validation' => (bool) env('DATA_QUALITY_STRICT_VALIDATION', false),

    // Entity-key canonicalization knobs (applied to sku/code fields).
    'canonicalize' => [
        'uppercase_keys'    => (bool) env('DATA_QUALITY_UPPERCASE_KEYS', true),
        'strip_leading_zeros' => (bool) env('DATA_QUALITY_STRIP_LEADING_ZEROS', false),
    ],

    // How many rows of the first chunk to profile for the column-health view.
    'profile_sample' => (int) env('DATA_QUALITY_PROFILE_SAMPLE', 2000),

    /*
    |--------------------------------------------------------------------------
    | Phase 2 — batch decision, readiness, idempotency
    |--------------------------------------------------------------------------
    */

    // Per-batch GREEN/AMBER/RED thresholds on the clean-promote rate (%). At or above
    // green_min → GREEN (detection ready); at or above amber_min → AMBER (auto-promote
    // clean, quarantine exceptions); below amber_min → RED (batch blocked). Override
    // per data type in `per_type`.
    'thresholds' => [
        'green_min' => (float) env('DATA_QUALITY_GREEN_MIN', 98),
        'amber_min' => (float) env('DATA_QUALITY_AMBER_MIN', 90),
        'per_type'  => [
            // 'inventory_levels' => ['green_min' => 99, 'amber_min' => 92],
        ],
    ],

    // Detection-readiness enforcement. When ON, the detection runner skips rules whose
    // dataset's latest batch is RED (blocked) — granular per dataset, not global. Default
    // OFF (behaviour-safe): readiness is computed and shown, but never suppresses a rule
    // until you flip this. See claude/data-quality-firewall.md.
    'readiness_enforcement' => (bool) env('DATA_QUALITY_READINESS_ENFORCEMENT', false),

    // Idempotent uploads: an identical file (same content fingerprint) already ingested
    // is skipped rather than re-promoted, so a re-upload can't duplicate canonical rows.
    'idempotent_uploads' => (bool) env('DATA_QUALITY_IDEMPOTENT_UPLOADS', true),
];
