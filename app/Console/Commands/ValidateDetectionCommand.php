<?php

namespace App\Console\Commands;

use App\Models\Anomaly;
use App\Models\SkuProfile;
use App\Models\Tenant;
use App\Services\Anomaly\AnomalyDetectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * B2 — Detection validation harness.
 *
 * Two things the product could previously only assert, this measures:
 *
 *  1. Noise reduction from the best-fit layer. The engine records, per rule,
 *     how many flags it EMITTED and how many it GATED (suppressed because the
 *     rule doesn't fit that SKU's demand segment). would-be = emitted + gated;
 *     gated / would-be is the noise the best-fit layer cut — the number that
 *     turns "technically interesting" into "materially fewer false alarms".
 *
 *  2. Precision from real outcomes. Investigations that were closed carry a
 *     was_false_positive flag. Joined back to their anomalies (and each
 *     anomaly's demand segment), that yields a precision proxy per rule and
 *     per segment — grounded in what investigators actually found, not theory.
 *
 * Running detection here is safe: runForTenant() upserts and is idempotent, the
 * same call the scheduler makes.
 */
class ValidateDetectionCommand extends Command
{
    protected $signature = 'detection:validate {--tenant= : Validate a single tenant} {--no-run : Skip re-running detection; only compute outcome precision}';

    protected $description = 'Measure best-fit noise reduction (emitted vs gated) and outcome-based precision per rule/segment';

    public function handle(AnomalyDetectionService $detector): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::where('id', $this->option('tenant'))->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants to validate.');
            return Command::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $this->line('');
            $this->info("═══ Tenant #{$tenant->id} — {$tenant->name} ═══");

            if (! $this->option('no-run')) {
                $this->reportNoiseReduction($tenant->id, $detector);
            }

            $this->reportPrecision($tenant->id);
        }

        return Command::SUCCESS;
    }

    /** Part 1: emitted vs gated per rule, from a fresh (idempotent) detection run. */
    private function reportNoiseReduction(int $tenantId, AnomalyDetectionService $detector): void
    {
        $this->line('Running detection…');
        $detector->runForTenant($tenantId);

        if (! $detector->gatingWasActive()) {
            $this->warn('Best-fit gating inactive (no sku_profiles for this tenant — run sku:profile first). Noise-reduction figures unavailable.');
            return;
        }

        $emitted = $detector->emittedByRule();
        $gated   = $detector->gatedByRule();
        $rules   = collect(array_keys($emitted + $gated))->sort()->values();

        $rows = [];
        $totalEmitted = 0;
        $totalGated   = 0;
        foreach ($rules as $rule) {
            $e = $emitted[$rule] ?? 0;
            $g = $gated[$rule] ?? 0;
            $wouldBe = $e + $g;
            $totalEmitted += $e;
            $totalGated   += $g;
            if ($wouldBe === 0) continue;
            $rows[] = [$rule, number_format($e), number_format($g), $this->pct($g, $wouldBe)];
        }

        $this->table(['Rule', 'Emitted', 'Gated', 'Noise cut'], $rows);

        $wouldBeTotal = $totalEmitted + $totalGated;
        $this->info(sprintf(
            'Best-fit layer: %s flags emitted, %s suppressed as off-segment noise → %s of would-be flags cut.',
            number_format($totalEmitted),
            number_format($totalGated),
            $this->pct($totalGated, $wouldBeTotal)
        ));
    }

    /** Part 2: precision proxy per rule and per segment, from recorded outcomes. */
    private function reportPrecision(int $tenantId): void
    {
        // Anomalies that belong to an investigation with a recorded outcome.
        $rows = DB::table('anomalies as a')
            ->join('investigation_outcomes as o', 'o.investigation_id', '=', 'a.investigation_id')
            ->where('a.tenant_id', $tenantId)
            ->whereNotNull('a.investigation_id')
            ->select('a.rule_type', 'a.sku', 'a.store_id', 'o.was_false_positive', 'o.outcome_type')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('Precision: no recorded outcomes yet for this tenant — nothing to measure.');
            return;
        }

        // Segment map for per-segment attribution (store profile, else chain).
        $segments = [];
        DB::table('sku_profiles')->where('tenant_id', $tenantId)
            ->select('store_id', 'sku', 'segment')->get()
            ->each(function ($p) use (&$segments) {
                $segments[$p->store_id . '|' . trim((string) $p->sku)] = $p->segment;
            });
        $segOf = function (?string $sku, $storeId) use ($segments): string {
            if ($sku === null) return SkuProfile::SEG_UNKNOWN;
            $sku = trim($sku);
            return $segments[($storeId ?? 0) . '|' . $sku]
                ?? $segments['0|' . $sku]
                ?? SkuProfile::SEG_UNKNOWN;
        };

        $byRule = [];
        $bySeg  = [];
        foreach ($rows as $r) {
            // Duplicates are neither a hit nor a false alarm — exclude from precision.
            if ($r->outcome_type === 'duplicate') continue;
            $fp = (bool) $r->was_false_positive;

            $byRule[$r->rule_type] ??= ['real' => 0, 'fp' => 0];
            $byRule[$r->rule_type][$fp ? 'fp' : 'real']++;

            $seg = $segOf($r->sku, $r->store_id);
            $bySeg[$seg] ??= ['real' => 0, 'fp' => 0];
            $bySeg[$seg][$fp ? 'fp' : 'real']++;
        }

        $mk = function (array $bucket): array {
            $out = [];
            ksort($bucket);
            foreach ($bucket as $k => $c) {
                $tot = $c['real'] + $c['fp'];
                if ($tot === 0) continue;
                $out[] = [$k, number_format($c['real']), number_format($c['fp']), $this->pct($c['real'], $tot)];
            }
            return $out;
        };

        $this->line('');
        $this->line('Precision by rule (from recorded outcomes):');
        $this->table(['Rule', 'Real', 'False +', 'Precision'], $mk($byRule));

        $this->line('Precision by demand segment:');
        $this->table(['Segment', 'Real', 'False +', 'Precision'], $mk($bySeg));
    }

    private function pct(int $num, int $den): string
    {
        if ($den === 0) return '—';
        return number_format(100 * $num / $den, 1) . '%';
    }
}
