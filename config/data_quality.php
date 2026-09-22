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
];
