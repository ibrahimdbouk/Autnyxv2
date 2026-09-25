<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Anomaly\BaselineCalculatorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * WP4.3 (audit H14) — undo sensitivity inflated by the Carbon 3 bug.
 *
 * Until W1, "dismissed within 10 minutes" was measured with a signed diff that
 * was always negative, so EVERY dismissal widened the rule's sensitivity for
 * the SKU. Only an explicit false positive (is_false_positive on an anomaly of
 * that tenant/rule/SKU) is kept: each baseline is reset to the default plus
 * one step per explicit false positive, capped. Dry run by default; running it
 * twice changes nothing the second time.
 */
class RepairBaselineSensitivityCommand extends Command
{
    protected $signature = 'baselines:repair-sensitivity {--tenant= : Only this tenant} {--apply : Write the changes}';

    protected $description = 'Reset baseline sensitivity inflated by pre-W1 dismissals, keeping explicit false positives (dry run unless --apply).';

    public function handle(): int
    {
        $rows = DB::select(
            "WITH fp AS (
                SELECT tenant_id, rule_type, sku, COUNT(*) AS n
                FROM anomalies WHERE is_false_positive = true
                GROUP BY tenant_id, rule_type, sku
             )
             SELECT b.id, b.tenant_id, b.rule_type, b.sku, b.store_id, b.sensitivity_multiplier AS m, b.fp_count,
                    LEAST(?::numeric, ?::numeric + ?::numeric * COALESCE(fp.n, 0)) AS target_m, COALESCE(fp.n, 0) AS target_fp
             FROM sku_baselines b
             LEFT JOIN fp ON fp.tenant_id = b.tenant_id AND fp.rule_type = b.rule_type AND fp.sku IS NOT DISTINCT FROM b.sku
             WHERE (b.fp_count > 0 OR b.sensitivity_multiplier > ?::numeric)" . ($this->option('tenant') ? ' AND b.tenant_id = ?' : ''),
            array_merge(
                [BaselineCalculatorService::MAX_SENSITIVITY, BaselineCalculatorService::DEFAULT_SENSITIVITY,
                 BaselineCalculatorService::FP_WIDEN_STEP, BaselineCalculatorService::DEFAULT_SENSITIVITY],
                $this->option('tenant') ? [(int) $this->option('tenant')] : []
            )
        );

        $changes = array_values(array_filter($rows, fn ($r) => abs((float) $r->m - (float) $r->target_m) > 0.0001 || (int) $r->fp_count !== (int) $r->target_fp));

        $byTenant = collect($changes)->groupBy('tenant_id')->map->count();
        $this->table(['Tenant', 'Baselines to reset'], $byTenant->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->line(count($changes) . ' baseline(s) to reset; ' . (count($rows) - count($changes)) . ' already correct.');

        if (! $this->option('apply')) {
            $this->warn('Dry run. Re-run with --apply to write.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($changes) {
            foreach ($changes as $r) {
                DB::table('sku_baselines')->where('id', $r->id)->update([
                    'sensitivity_multiplier' => (float) $r->target_m,
                    'fp_count'               => (int) $r->target_fp,
                    'updated_at'             => now(),
                ]);
            }
            foreach (collect($changes)->groupBy('tenant_id') as $tenantId => $list) {
                AuditLog::create([
                    'tenant_id'   => (int) $tenantId,
                    'event_type'  => 'baseline_sensitivity_repaired',
                    'description' => count($list) . ' baseline sensitivity value(s) reset after the pre-W1 dismissal bug (WP4.3).',
                    'new_value'   => ['baselines' => count($list)],
                ]);
            }
        });
        $this->info('Applied.');

        return self::SUCCESS;
    }
}
