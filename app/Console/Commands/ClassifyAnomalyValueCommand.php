<?php

namespace App\Console\Commands;

use App\Support\Detection\ValueModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * WP4.4 — give anomalies created before the value model their value_type
 * (lost revenue / capital at cost / upside / data quality), from their rule and
 * direction. Only fills blanks, so it can run any number of times.
 */
class ClassifyAnomalyValueCommand extends Command
{
    protected $signature = 'anomalies:classify-value {--tenant= : Only this tenant}';

    protected $description = 'Classify existing anomalies by the kind of money they carry (fills blanks only).';

    public function handle(): int
    {
        $in = fn (array $rules) => "'" . implode("','", $rules) . "'";
        $lost = $in(ValueModel::rulesOfType(ValueModel::LOST_REVENUE));
        $cap  = $in(ValueModel::rulesOfType(ValueModel::CAPITAL));
        $up   = $in(ValueModel::rulesOfType(ValueModel::UPSIDE));

        $n = DB::update(
            "UPDATE anomalies SET value_type = CASE
                WHEN rule_type IN ({$lost}) AND ((context->>'direction') IN ('above', 'gained')
                     OR (rule_type = 'channel_mix_shift' AND COALESCE(context->>'shift_pct', '0') ~ '^[0-9.]+$'
                         AND (context->>'shift_pct')::numeric > 0)) THEN 'upside'
                WHEN rule_type IN ({$lost}) THEN 'lost_revenue'
                WHEN rule_type IN ({$cap})  THEN 'capital_at_cost'
                WHEN rule_type IN ({$up})   THEN 'upside'
                ELSE 'data_quality' END
             WHERE value_type IS NULL" . ($this->option('tenant') ? ' AND tenant_id = ?' : ''),
            $this->option('tenant') ? [(int) $this->option('tenant')] : []
        );

        $this->info("{$n} anomaly(ies) classified.");

        return self::SUCCESS;
    }
}
