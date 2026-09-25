<?php

return [

    /*
    | WP5.4 (D7) — AI budgets and resilience. Per-tenant overrides live in the
    | tenant's settings (ai_daily_calls / ai_daily_tokens), set from Ops.
    */

    'daily_calls'         => (int) env('AI_DAILY_CALLS', 300),
    'daily_tokens'        => (int) env('AI_DAILY_TOKENS', 2_000_000),
    'per_user_per_minute' => (int) env('AI_PER_USER_PER_MINUTE', 10),
    'max_attempts'        => (int) env('AI_MAX_ATTEMPTS', 3),
    'circuit_failures'    => (int) env('AI_CIRCUIT_FAILURES', 5),

    /** Most investigations one nightly narrate run writes up (highest revenue at risk first). */
    'narrate_per_run'     => (int) env('AI_NARRATE_PER_RUN', 50),

    /** Prompt caps — beyond these a list is summarised as "+N more". */
    'prompt_max_anomalies' => 25,
    'prompt_max_evidence'  => 30,
];
