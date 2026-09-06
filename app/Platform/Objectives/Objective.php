<?php

namespace App\Platform\Objectives;

/**
 * P2.2 — a business objective the platform can optimise toward: availability,
 * margin, waste, working capital (or "general" = balanced). Objectives are the
 * lens; the {@see ObjectiveScorer} re-weights outputs so the same anomaly ranks
 * differently depending on what the tenant is trying to achieve right now.
 *
 * `defaultWeight` is the weight applied to a rule type this objective does not
 * explicitly care about — low for a focused objective, 1.0 for "general".
 */
class Objective
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly float $defaultWeight = 1.0,
    ) {
    }
}
