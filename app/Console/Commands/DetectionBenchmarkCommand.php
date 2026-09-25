<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Detection\TenantDetectionRunner;
use App\Services\Inventory\InventoryCurrentService;
use App\Services\Ops\TenantOffboardingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * W10 — detection precision / recall against KNOWN answers.
 *
 * Seeds a synthetic retailer with planted problems at known (store, SKU)
 * positions — sales collapses, demand spikes, shrink, stock-outs — plus
 * planted PROMOTIONS that must not be flagged (a negative control), on a
 * background of ordinary noise. Runs the nightly analytics exactly as
 * production does (profiles, baselines, full v2 detection with the default
 * rule settings) and scores what was flagged against the plant:
 *
 *   recall     planted positions found by that family's rules
 *   precision  flags of that family's rules that land on a planted position
 *
 * Real-tenant precision still comes from investigators' outcomes
 * (detection:validate); this is the reproducible number before there are any.
 */
class DetectionBenchmarkCommand extends Command
{
    protected $signature = 'detection:benchmark
        {--stores=20} {--skus=500} {--days=90} {--signal-every=32 : About 1 in N positions carries each planted problem (power of 2)}
        {--json : Print the result as JSON} {--keep} {--allow-production}';

    protected $description = 'Score detection against a synthetic retailer with planted problems (precision / recall per family)';

    /** family => [rules that detect it, truth SQL predicate on (s = store, p = product)] */
    private function families(int $m, int $mi): array
    {
        return [
            'Sales collapse (90% drop, one store)' => [['sales_drop', 'store_outlier', 'demand_seasonality_breach'], "(hashtext(s.code || p.sku) & {$m}) = 1"],
            'Demand spike (4×, one store)'         => [['sales_spike', 'demand_seasonality_breach'], "(hashtext(s.code || p.sku) & {$m}) = 2"],
            'Shrink (stock falling weekly)'        => [['inventory_shrinkage', 'cumulative_shrink', 'phantom_inventory'], "(hashtext(s.code || p.sku || 'inv') & {$mi}) = 3"],
            'Stock-out (zero on hand)'             => [['stockout_risk', 'safety_stock_breach'], "(hashtext(s.code || p.sku || 'inv') & {$mi}) = 4"],
        ];
    }

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('allow-production')) {
            $this->error('Refusing to write a synthetic tenant into production (pass --allow-production).');

            return self::FAILURE;
        }
        $every = max(8, (int) $this->option('signal-every'));
        $m  = $every - 1;
        $mi = 2 * $every - 1;

        $tenant = Tenant::create(['name' => 'Benchmark ' . now()->format('Y-m-d H:i'), 'slug' => 'benchmark-' . Str::lower(Str::random(8)),
            'status' => 'active', 'currency' => 'AED', 'settings' => ['detection_rules_v2' => true]]);
        $t = $tenant->id;

        try {
            \App\Support\Testing\SyntheticRetailer::seed($t, (int) $this->option('stores'), (int) $this->option('skus'), (int) $this->option('days'), 0.9, $every, benchmark: true);
            app(InventoryCurrentService::class)->rebuild($t);
            foreach (['sku:profile', 'stores:profile', 'replenishment:compute', 'baselines:compute'] as $cmd) {
                Artisan::call($cmd, ['--tenant' => $t]);
            }
            $runner = app(TenantDetectionRunner::class);
            $runner->run($t, 'full');
            $result = $this->score($t, $m, $mi, $runner);
        } finally {
            if (! $this->option('keep')) {
                app(TenantOffboardingService::class)->eraseNow($t);
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $this->table(['Planted problem', 'Planted', 'Found', 'Recall', 'Flags', 'On a planted position', 'Precision'],
            array_map(fn ($f) => [$f['family'], $f['planted'], $f['found'], $f['recall'], $f['flags'], $f['true_flags'], $f['precision']], $result['families']));
        $p = $result['promotions'];
        $this->info("Promotions (negative control): {$p['planted']} planted 3× promo weeks; {$p['flagged']} flagged as a demand anomaly; {$p['suppressed']} flags suppressed because the promotion explains them.");
        $this->line("Overall: {$result['overall']['true_flags']} of {$result['overall']['flags']} flags from these families land on a planted problem (precision {$result['overall']['precision']}); recall {$result['overall']['recall']}.");

        return self::SUCCESS;
    }

    private function score(int $t, int $m, int $mi, TenantDetectionRunner $runner): array
    {
        $live = "a.tenant_id = ? AND a.dismissed_at IS NULL AND a.lifecycle_state <> 'resolved'";
        $out = [];
        $allFlags = $allTrue = $allPlanted = $allFound = 0;

        foreach ($this->families($m, $mi) as $family => [$rules, $truth]) {
            $in = implode(',', array_fill(0, count($rules), '?'));
            $planted = (int) DB::selectOne("SELECT COUNT(*) AS n FROM stores s CROSS JOIN products p WHERE s.tenant_id = ? AND p.tenant_id = ? AND {$truth}", [$t, $t])->n;
            // Found: a flag of the family on that position (a store-level flag), or on its SKU chain-wide.
            $found = (int) DB::selectOne("SELECT COUNT(*) AS n FROM stores s CROSS JOIN products p
                WHERE s.tenant_id = ? AND p.tenant_id = ? AND {$truth}
                  AND EXISTS (SELECT 1 FROM anomalies a WHERE {$live} AND a.rule_type IN ({$in}) AND a.sku = p.sku AND (a.store_id = s.id OR a.store_id IS NULL))",
                array_merge([$t, $t, $t], $rules))->n;
            $flags = (int) DB::selectOne("SELECT COUNT(*) AS n FROM anomalies a WHERE {$live} AND a.rule_type IN ({$in})", array_merge([$t], $rules))->n;
            $trueFlags = (int) DB::selectOne("SELECT COUNT(*) AS n FROM anomalies a WHERE {$live} AND a.rule_type IN ({$in})
                AND EXISTS (SELECT 1 FROM stores s JOIN products p ON p.tenant_id = s.tenant_id AND p.sku = a.sku
                            WHERE s.tenant_id = a.tenant_id AND (a.store_id IS NULL OR s.id = a.store_id) AND {$truth})",
                array_merge([$t], $rules))->n;

            $out[] = ['family' => $family, 'rules' => $rules, 'planted' => $planted, 'found' => $found, 'recall' => $this->pct($found, $planted),
                'flags' => $flags, 'true_flags' => $trueFlags, 'precision' => $this->pct($trueFlags, $flags)];
            $allFlags += $flags;
            $allTrue += $trueFlags;
            $allPlanted += $planted;
            $allFound += $found;
        }

        $promoTruth = "(hashtext(s.code || p.sku) & {$m}) = 5";
        $promoPlanted = (int) DB::selectOne("SELECT COUNT(*) AS n FROM stores s CROSS JOIN products p WHERE s.tenant_id = ? AND p.tenant_id = ? AND {$promoTruth}", [$t, $t])->n;
        $promoFlagged = (int) DB::selectOne("SELECT COUNT(*) AS n FROM anomalies a WHERE {$live} AND a.rule_type IN ('sales_spike', 'demand_seasonality_breach', 'store_outlier', 'sales_drop')
            AND EXISTS (SELECT 1 FROM stores s JOIN products p ON p.tenant_id = s.tenant_id AND p.sku = a.sku
                        WHERE s.tenant_id = a.tenant_id AND (a.store_id = s.id OR a.store_id IS NULL) AND {$promoTruth})", [$t])->n;

        return [
            'run_at'     => now()->toIso8601String(),
            'config'     => ['stores' => (int) $this->option('stores'), 'skus' => (int) $this->option('skus'), 'days' => (int) $this->option('days'), 'signal_every' => $m + 1],
            'families'   => $out,
            'promotions' => ['planted' => $promoPlanted, 'flagged' => $promoFlagged, 'suppressed' => array_sum($runner->lastPromoSuppressed())],
            'overall'    => ['flags' => $allFlags, 'true_flags' => $allTrue, 'precision' => $this->pct($allTrue, $allFlags), 'recall' => $this->pct($allFound, $allPlanted)],
        ];
    }

    private function pct(int $a, int $b): string
    {
        return $b > 0 ? number_format(100 * $a / $b, 1) . '%' : '—';
    }
}
