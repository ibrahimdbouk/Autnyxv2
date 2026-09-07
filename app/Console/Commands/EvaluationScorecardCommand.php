<?php

namespace App\Console\Commands;

use App\Platform\Evaluation\IntelligenceScorecard;
use Illuminate\Console\Command;

/**
 * P4.4 — show a tenant's intelligence quality scorecard (overall + per intent
 * type): adoption, success rate, realization, and calibration gap. Read-only.
 */
class EvaluationScorecardCommand extends Command
{
    protected $signature = 'evaluation:scorecard {tenant : Tenant id}';

    protected $description = "Show a tenant's intelligence quality scorecard from decision memory.";

    public function handle(IntelligenceScorecard $scorecard): int
    {
        $tenantId = (int) $this->argument('tenant');
        $card = $scorecard->compute($tenantId);

        $o = $card['overall'];
        if ($o['n'] === 0) {
            $this->info("No decision cases for tenant {$tenantId} yet — nothing to evaluate.");
            return self::SUCCESS;
        }

        $this->info("Overall: {$o['n']} cases, {$o['resolved']} resolved. "
            . "adoption=" . $this->pct($o['adoption_rate']) . ", success=" . $this->pct($o['success_rate'])
            . ", avg realization=" . ($o['avg_realization'] ?? '—')
            . ", calibration gap=" . ($o['calibration_gap'] ?? '—'));

        if ($card['by_intent_type'] !== []) {
            $this->line('');
            $this->table(
                ['intent_type', 'n', 'resolved', 'adoption', 'success', 'avg_real', 'calibration_gap'],
                array_map(fn (array $r) => [
                    $r['dim_key'], $r['n'], $r['resolved'],
                    $this->pct($r['adoption_rate']), $this->pct($r['success_rate']),
                    $r['avg_realization'] ?? '—', $r['calibration_gap'] ?? '—',
                ], $card['by_intent_type']),
            );
        }

        return self::SUCCESS;
    }

    private function pct(?float $v): string
    {
        return $v === null ? '—' : round($v * 100, 1) . '%';
    }
}
