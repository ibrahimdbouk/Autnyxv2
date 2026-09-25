<?php

namespace App\Console\Commands;

use App\Models\AnomalySetting;
use Illuminate\Console\Command;

/**
 * WP7.4 (audit L9) — reconcile stored rule settings with the code defaults:
 * keep only real overrides, stamp the settings version. Dry run by default.
 */
class SyncAnomalySettingsCommand extends Command
{
    protected $signature = 'anomaly-settings:sync {--tenant= : One tenant id} {--apply : Write the changes}';

    protected $description = 'Reconcile anomaly settings with the current code defaults (overrides only)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $rows  = AnomalySetting::query()
            ->where('settings_version', '<', AnomalySetting::SETTINGS_VERSION)
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', (int) $t))
            ->orderBy('id');

        $seen = 0;
        $changed = 0;
        $kept = 0;
        foreach ($rows->lazyById(500) as $s) {
            $seen++;
            $overrides = AnomalySetting::overridesOnly($s->rule_type, $s->thresholds);
            if ($overrides !== ($s->thresholds ?: null)) {
                $changed++;
            }
            if ($overrides) {
                $kept++;
                $this->line("tenant {$s->tenant_id} {$s->rule_type}: keeps " . json_encode($overrides));
            }
            if ($apply) {
                $s->thresholds = $overrides;
                $s->save();   // the saving hook stamps the settings version
            }
        }

        $this->info(($apply ? 'Applied' : 'Dry run') . ": {$seen} rows behind version " . AnomalySetting::SETTINGS_VERSION
            . ", {$changed} with seeded defaults removed, {$kept} with real overrides kept.");

        return self::SUCCESS;
    }
}
