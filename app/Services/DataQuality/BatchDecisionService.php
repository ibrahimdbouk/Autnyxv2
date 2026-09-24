<?php

namespace App\Services\DataQuality;

use App\Models\ImportQuality;

/**
 * Turns a batch's counts into a GREEN / AMBER / RED decision against configurable
 * per-tenant / per-data-type thresholds. GREEN = detection ready; AMBER = auto-promote
 * clean + exceptions to review; RED = blocked (materially bad, or a duplicate upload).
 * This is the autonomy envelope: clean & amber batches never need approval; only RED
 * pauses (with an alert + human override). See claude/data-quality-firewall.md.
 */
class BatchDecisionService
{
    /** @return array{state:string, decision:string, blocked:bool} */
    public function decide(string $dataType, int $promoted, int $quarantined, bool $isDuplicate): array
    {
        if ($isDuplicate) {
            // WP3.6: a harmless re-upload — nothing new, nothing wrong. Not RED:
            // it must not block the dataset's detection rules.
            return [
                'state'    => ImportQuality::STATE_DUPLICATE,
                'decision' => 'Duplicate upload — identical file already ingested; skipped (no new data).',
                'blocked'  => false,
            ];
        }

        $seen = $promoted + $quarantined;
        if ($seen === 0) {
            return ['state' => ImportQuality::STATE_GREEN, 'decision' => 'No rows.', 'blocked' => false];
        }

        $passRate = round(100 * $promoted / $seen, 1);
        [$green, $amber] = $this->thresholds($dataType);

        if ($passRate >= $green) {
            return [
                'state'    => ImportQuality::STATE_GREEN,
                'decision' => "Detection ready — {$passRate}% clean ({$promoted} promoted, {$quarantined} quarantined).",
                'blocked'  => false,
            ];
        }
        if ($passRate >= $amber) {
            return [
                'state'    => ImportQuality::STATE_AMBER,
                'decision' => "Auto-promoted {$promoted} clean rows; {$quarantined} quarantined for review ({$passRate}% clean).",
                'blocked'  => false,
            ];
        }

        return [
            'state'    => ImportQuality::STATE_RED,
            'decision' => "Blocked — only {$passRate}% clean ({$quarantined} of {$seen} rows failed). Resolve or explicitly promote.",
            'blocked'  => true,
        ];
    }

    /** @return array{0:float,1:float} [green_min, amber_min] */
    private function thresholds(string $dataType): array
    {
        $t      = config('data_quality.thresholds', []);
        $green  = (float) ($t['green_min'] ?? 98);
        $amber  = (float) ($t['amber_min'] ?? 90);
        $perType = $t['per_type'][$dataType] ?? null;
        if (is_array($perType)) {
            $green = (float) ($perType['green_min'] ?? $green);
            $amber = (float) ($perType['amber_min'] ?? $amber);
        }

        return [$green, $amber];
    }
}
