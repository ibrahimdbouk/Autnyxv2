<?php

namespace App\Console\Commands;

use App\Models\Anomaly;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use App\Services\Anomaly\BaselineCalculatorService;
use App\Support\Detection\ValueModel;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * WP4.6 — compare the current rules (v1) with the corrected ones (v2) for one
 * tenant, without writing anything: per rule, how many anomalies each set
 * would flag and the money they carry, plus what is live today.
 *
 * v1 runs as a dry run. v2 runs as a dry run on v2 baselines, which are built
 * inside a transaction that is rolled back — so the database is untouched.
 */
class DetectionDiffCommand extends Command
{
    protected $signature = 'detection:diff {--tenant= : Tenant ID (required)} {--json : Also save the report to storage/app/detection-diff}';

    protected $description = 'Dry-run the current and corrected detection rules side by side for a tenant (writes nothing).';

    public function handle(AnomalyDetectionService $detector, BaselineCalculatorService $baselines): int
    {
        $tenant = Tenant::find((int) $this->option('tenant'));
        if (! $tenant) {
            $this->error('Pass a valid --tenant=<id>.');

            return self::FAILURE;
        }
        @ini_set('memory_limit', '1024M');

        $started = microtime(true);
        $v1 = collect($detector->dryRun($tenant->id, false));
        if ($detector->lastRunDeferred) {
            $this->warn('Detection is deferred for this tenant right now (an import is in flight): ' . $detector->pendingImportBlock($tenant->id));

            return self::FAILURE;
        }

        DB::beginTransaction();
        try {
            $baselines->computeForTenantV2($tenant->id);
            $v2 = collect($detector->dryRun($tenant->id, true));
            $suppressed = $detector->suppressedByRule();
        } finally {
            DB::rollBack();
        }

        $live = Anomaly::where('tenant_id', $tenant->id)->active()
            ->selectRaw('rule_type, COUNT(*) AS n')->groupBy('rule_type')->pluck('n', 'rule_type');

        $rules = $v1->pluck('rule')->merge($v2->pluck('rule'))->merge($live->keys())->unique()->sort()->values();
        $rows = [];
        foreach ($rules as $rule) {
            $a = $v1->where('rule', $rule);
            $b = $v2->where('rule', $rule);
            $rows[] = [
                'rule'        => $rule,
                'live_now'    => (int) ($live[$rule] ?? 0),
                'v1_flags'    => $a->count(),
                'v1_subjects' => $a->pluck('identity')->unique()->count(),
                'v2_flags'    => $b->count(),
                'v2_value'    => round((float) $b->sum('value'), 2),
                'v2_type'     => $b->first()['type'] ?? ValueModel::type($rule),
                'v2_quiet'    => (int) ($suppressed[$rule] ?? 0),
            ];
        }

        $this->info("Detection diff — tenant {$tenant->id} ({$tenant->name}) — " . round(microtime(true) - $started, 1) . 's, nothing written');
        $this->table(['Rule', 'Live now', 'v1 flags', 'v1 subjects', 'v2 flags', 'v2 value', 'v2 money type', 'v2 kept quiet (dismissed)'],
            array_map(fn ($r) => [$r['rule'], $r['live_now'], $r['v1_flags'], $r['v1_subjects'], $r['v2_flags'],
                number_format($r['v2_value'], 0), $r['v2_type'], $r['v2_quiet']], $rows));

        $totals = [
            'live_now'              => array_sum(array_column($rows, 'live_now')),
            'v1_flags'              => $v1->count(),
            'v2_flags'              => $v2->count(),
            'v1_value_sum'          => round((float) $v1->sum('value'), 2),
            'v2_lost_revenue'       => $this->dedup($v2, ValueModel::LOST_REVENUE),
            'v2_capital_at_cost'    => $this->dedup($v2, ValueModel::CAPITAL),
            'v2_upside'             => round((float) $v2->where('type', ValueModel::UPSIDE)->sum('value'), 2),
            'v1_store_outlier_null_store' => $v1->where('rule', 'store_outlier')->whereNull('store_id')->count(),
        ];
        $this->newLine();
        foreach ($totals as $k => $v) {
            $this->line(str_pad($k, 30) . (is_float($v) ? number_format($v, 0) : $v));
        }

        if ($this->option('json')) {
            $path = "detection-diff/tenant-{$tenant->id}-" . now()->format('Ymd-His') . '.json';
            Storage::disk('local')->put($path, json_encode(['tenant' => $tenant->id, 'rows' => $rows, 'totals' => $totals], JSON_PRETTY_PRINT));
            $this->line("Saved: storage/app/{$path}");
        }

        return self::SUCCESS;
    }

    /** Lost revenue / capital counted once per SKU (chain vs stores: the larger), as revenue at risk is. */
    private function dedup(Collection $rows, string $type): float
    {
        $bySku = $other = [];
        foreach ($rows->where('type', $type) as $r) {
            if ($r['sku'] === null) {
                $k = ($r['store_id'] ?? '-') . '|' . ($r['subject'] ?? $r['rule']);
                $other[$k] = max($other[$k] ?? 0, $r['value']);
            } elseif ($r['store_id'] === null) {
                $bySku[$r['sku']]['chain'] = max($bySku[$r['sku']]['chain'] ?? 0, $r['value']);
            } else {
                $bySku[$r['sku']]['stores'][$r['store_id']] = max($bySku[$r['sku']]['stores'][$r['store_id']] ?? 0, $r['value']);
            }
        }
        $total = array_sum($other);
        foreach ($bySku as $s) {
            $total += max($s['chain'] ?? 0, array_sum($s['stores'] ?? []));
        }

        return round($total, 2);
    }
}
