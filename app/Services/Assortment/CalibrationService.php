<?php

namespace App\Services\Assortment;

use App\Models\AssortmentCalibration;
use App\Models\AssortmentGap;
use App\Models\AssortmentPlan;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Intelligence\Substitution\TransferEstimator;
use Illuminate\Support\Facades\DB;

/**
 * v1.5 Phase 5 — learning from measured results.
 *
 *   stats      for each rule (add, delist, stockout-hidden): how many decisions
 *              were measured, how often they worked, measured ÷ estimated,
 *              how often the result fell inside the estimated range, and — for
 *              delists — where buyers really went against the transfer estimate.
 *              Plans are reported beside them (not used to tune: a category's
 *              sales move for many reasons at once).
 *   propose    when a rule has at least MIN_MEASURED measured results and its
 *              figures are off by more than a tolerance, a new versioned set of
 *              adjustments is proposed, with each change and its reason in words.
 *   approve    an admin approves it: from the next run the engine applies it,
 *              and every decision and plan keeps the version it was made under.
 *   reject / retire   nothing changes / the rules go back as built.
 *
 * Production rules never change silently: nothing here is applied until approved.
 */
class CalibrationService
{
    /** Measured results (with enough comparison stores) a rule needs before its figures are re-tuned. */
    public const MIN_MEASURED = 10;

    public const VALUE_BOUNDS = [0.5, 1.5];

    public const VALUE_TOLERANCE = 0.10;

    public const WIDEN_BOUNDS = [0.8, 1.5];

    public const WIDEN_TOLERANCE = 0.05;

    /** Ranges are meant to hold about 80% of results. */
    public const TARGET_COVERAGE = 0.80;

    public const SHARE_BOUND = 0.20;

    public const SHARE_TOLERANCE = 0.03;

    /** A rule whose measured decisions work less often than this is one confidence tier lower; back up at RESTORE_SUCCESS. */
    public const MIN_SUCCESS = 0.40;

    public const RESTORE_SUCCESS = 0.60;

    public const TYPES = [AssortmentGap::TYPE_ADD, AssortmentGap::TYPE_DELIST, AssortmentGap::TYPE_STOCKOUT_HIDDEN];

    public static function identity(): array
    {
        return [
            'value_scale' => [AssortmentGap::TYPE_ADD => 1.0, AssortmentGap::TYPE_STOCKOUT_HIDDEN => 1.0],
            'range_widen' => [AssortmentGap::TYPE_ADD => 1.0, AssortmentGap::TYPE_DELIST => 1.0, AssortmentGap::TYPE_STOCKOUT_HIDDEN => 1.0],
            'tier_shift'  => [AssortmentGap::TYPE_ADD => 0, AssortmentGap::TYPE_DELIST => 0, AssortmentGap::TYPE_STOCKOUT_HIDDEN => 0],
            'share_shift' => 0.0,
        ];
    }

    /** The approved calibration in force (with its version), or null: the rules as built. */
    public static function active(int $tenantId): ?array
    {
        $c = AssortmentCalibration::where('tenant_id', $tenantId)->where('status', AssortmentCalibration::STATUS_APPROVED)
            ->orderByDesc('version')->first();

        return $c ? array_replace_recursive(self::identity(), (array) $c->adjustments) + ['version' => (int) $c->version] : null;
    }

    // ── Applying it (pure) ────────────────────────────────────────────────────

    /** Transferable demand shifted by the calibration (unknown stays unknown). */
    public static function shiftShare(array $t, ?array $cal): array
    {
        $s = (float) ($cal['share_shift'] ?? 0.0);
        if ($s === 0.0 || ($t['mid'] ?? null) === null) {
            return $t;
        }
        foreach (['low', 'mid', 'high'] as $k) {
            $t[$k] = round(max(0.0, min(TransferEstimator::TOTAL_CAP, (float) $t[$k] + $s)), 4);
        }
        $t['calibrated'] = $s;

        return $t;
    }

    /** A decision's value range under the calibration: scaled around its mid, and widened or narrowed. */
    public static function values(array $values, string $type, ?array $cal): array
    {
        if ($cal === null) {
            return $values;
        }
        [$low, $mid, $high] = $values;
        $f = (float) ($cal['value_scale'][$type] ?? 1.0);
        $w = (float) ($cal['range_widen'][$type] ?? 1.0);
        $m = $mid * $f;

        return [$m - ($mid - $low) * $f * $w, $m, $m + ($high - $mid) * $f * $w];
    }

    /** What a decision keeps of the calibration it was made under. */
    public static function stamp(?array $cal, string $type): ?array
    {
        return $cal === null ? null : [
            'version' => (int) $cal['version'], 'value_scale' => (float) ($cal['value_scale'][$type] ?? 1.0),
            'range_widen' => (float) ($cal['range_widen'][$type] ?? 1.0), 'tier_shift' => (int) ($cal['tier_shift'][$type] ?? 0),
            'share_shift' => (float) ($cal['share_shift'] ?? 0.0),
        ];
    }

    // ── What the measured results say ─────────────────────────────────────────

    /** @return array<string,mixed> per rule type, plus plans */
    public function stats(int $tenantId): array
    {
        $out = [];
        foreach (self::TYPES as $type) {
            $out[$type] = ['n' => 0, 'strong' => 0, 'weak' => 0, 'success' => 0, 'partial' => 0, 'failure' => 0, 'strong_success' => 0, 'strong_judged' => 0,
                'success_rate' => null, 'ratio_median' => null, 'coverage' => null, 'with_range' => 0, 'share_bias' => null, 'share_n' => 0, 'by_tier' => []];
        }
        $ratios = $inside = $bias = [];

        AssortmentGap::where('tenant_id', $tenantId)->whereNotNull('measured_at')
            ->select(['id', 'type', 'confidence_tier', 'evidence', 'measurement'])->orderBy('id')
            ->chunk(500, function ($gaps) use (&$out, &$ratios, &$inside, &$bias) {
                foreach ($gaps as $g) {
                    $type = $g->type;
                    if (! isset($out[$type])) {
                        continue;
                    }
                    $m = (array) ($g->measurement ?? []);
                    $e = (array) ($g->evidence ?? []);
                    $actual = isset($m['uplift_per_year']) ? (float) $m['uplift_per_year'] : null;
                    if ($actual === null) {
                        continue;
                    }
                    $verdict = (string) ($m['verdict'] ?? '');
                    $strong = ($m['strength'] ?? 'weak') !== 'weak';
                    $o = &$out[$type];
                    $o['n']++;
                    $o[$strong ? 'strong' : 'weak']++;
                    $tier = (string) $g->confidence_tier;
                    $o['by_tier'][$tier]['n'] = ($o['by_tier'][$tier]['n'] ?? 0) + 1;
                    if (in_array($verdict, ['success', 'partial', 'failure'], true)) {
                        $o[$verdict]++;
                        $o['by_tier'][$tier][$verdict] = ($o['by_tier'][$tier][$verdict] ?? 0) + 1;
                        if ($strong) {
                            $o['strong_judged']++;
                            $o['strong_success'] += $verdict === 'success' ? 1 : 0;
                        }
                    }
                    unset($o);
                    if (! $strong) {
                        continue;
                    }
                    $cal = (array) ($e['calibration'] ?? []);
                    $expected = isset($m['expected_per_year']) ? (float) $m['expected_per_year'] : (isset($e['expected_sales_change_per_year']) ? (float) $e['expected_sales_change_per_year'] : null);
                    // Measured against the estimate before any calibration, so a new factor is absolute.
                    if ($type !== AssortmentGap::TYPE_DELIST && $expected !== null && $expected > 0) {
                        $raw = $expected / max(1e-9, (float) ($cal['value_scale'] ?? 1.0));
                        $ratios[$type][] = $actual / $raw;
                    }
                    $range = $this->expectedRange($type, $e);
                    if ($range !== null) {
                        $inside[$type][] = $actual >= $range[0] - 1e-6 && $actual <= $range[1] + 1e-6 ? 1 : 0;
                    }
                    if ($type === AssortmentGap::TYPE_DELIST) {
                        $current = (float) ($e['current_sales_per_year'] ?? $e['current_per_year'] ?? 0);
                        $share = $e['transfer']['share'][1] ?? null;
                        if ($current > 0 && $share !== null) {
                            $implied = max(0.0, min(1.0, 1 - max(0.0, -$actual) / $current));
                            $bias[] = $implied - ((float) $share - (float) ($cal['share_shift'] ?? 0.0));
                        }
                    }
                }
            });

        foreach (self::TYPES as $type) {
            $o = &$out[$type];
            // How often it worked, judged only on results with enough comparison stores.
            $o['success_rate'] = $o['strong_judged'] > 0 ? round($o['strong_success'] / $o['strong_judged'], 4) : null;
            $o['ratio_median'] = isset($ratios[$type]) ? round(self::median($ratios[$type]), 4) : null;
            $o['with_range'] = count($inside[$type] ?? []);
            $o['coverage'] = $o['with_range'] > 0 ? round(array_sum($inside[$type]) / $o['with_range'], 4) : null;
            unset($o);
        }
        $out[AssortmentGap::TYPE_DELIST]['share_bias'] = $bias !== [] ? round(self::median($bias), 4) : null;
        $out[AssortmentGap::TYPE_DELIST]['share_n'] = count($bias);

        $plans = AssortmentPlan::where('tenant_id', $tenantId)->whereNotNull('measured_at')->get(['measurement']);
        $pr = [];
        $p = ['n' => $plans->count(), 'strong' => 0, 'success' => 0, 'partial' => 0, 'failure' => 0, 'ratio_median' => null];
        foreach ($plans as $pl) {
            $m = (array) $pl->measurement;
            if (($m['strength'] ?? 'weak') === 'weak') {
                continue;
            }
            $p['strong']++;
            if (in_array($m['verdict'] ?? '', ['success', 'partial', 'failure'], true)) {
                $p[$m['verdict']]++;
            }
            if (($m['expected_per_year'] ?? 0) > 0 && isset($m['uplift_per_year'])) {
                $pr[] = (float) $m['uplift_per_year'] / (float) $m['expected_per_year'];
            }
        }
        $p['ratio_median'] = $pr !== [] ? round(self::median($pr), 4) : null;
        $out['plans'] = $p;

        return $out;
    }

    /** The decision's estimated sales change a year, low to high (what "inside the range" is judged on). */
    private function expectedRange(string $type, array $e): ?array
    {
        $share = $e['transfer']['share'] ?? null;
        if ($share === null) {
            return null;
        }
        if ($type === AssortmentGap::TYPE_DELIST) {
            $c = (float) ($e['current_sales_per_year'] ?? $e['current_per_year'] ?? 0);

            return $c > 0 ? [-$c * (1 - (float) $share[0]), -$c * (1 - (float) $share[2])] : null;
        }
        $g = (float) ($e['gross_sales_per_year'] ?? 0);

        return $g > 0 ? [$g * (1 - (float) $share[2]), $g * (1 - (float) $share[0])] : null;
    }

    // ── Proposing, approving ──────────────────────────────────────────────────

    /** A new proposal when the measured results call for one (null when nothing would change, or not enough is measured). */
    public function propose(Tenant $tenant): ?AssortmentCalibration
    {
        $tenantId = (int) $tenant->id;
        $stats = $this->stats($tenantId);
        $now = self::active($tenantId) ?? self::identity() + ['version' => 0];
        $adj = $now;
        unset($adj['version']);
        $reasons = [];
        $label = ['add' => 'Adds', 'delist' => 'Delists', 'stockout_hidden' => 'Stockout fixes'];
        $pct = fn (float $v) => (int) round($v * 100) . '%';

        foreach (self::TYPES as $type) {
            $s = $stats[$type];
            if ($s['strong'] < self::MIN_MEASURED) {
                continue;
            }
            if ($type !== AssortmentGap::TYPE_DELIST && $s['ratio_median'] !== null) {
                $v = round(max(self::VALUE_BOUNDS[0], min(self::VALUE_BOUNDS[1], $s['ratio_median'])), 2);
                if (abs($v - (float) $now['value_scale'][$type]) >= self::VALUE_TOLERANCE) {
                    $adj['value_scale'][$type] = $v;
                    $reasons[] = "{$label[$type]}: estimates × " . number_format($v, 2) . " (was × " . number_format((float) $now['value_scale'][$type], 2)
                        . "). Measured " . mb_strtolower($label[$type]) . " delivered a median " . $pct($s['ratio_median']) . " of the estimate before calibration ({$s['strong']} measured).";
                }
            }
            if ($s['coverage'] !== null && $s['with_range'] >= self::MIN_MEASURED) {
                $w0 = (float) $now['range_widen'][$type];
                $w = $w0;
                if ($s['coverage'] < self::TARGET_COVERAGE - 0.2) {
                    $w = $w0 * (1 + (self::TARGET_COVERAGE - $s['coverage']));
                } elseif ($s['coverage'] > 0.95) {
                    $w = $w0 * 0.85;
                }
                $w = round(max(self::WIDEN_BOUNDS[0], min(self::WIDEN_BOUNDS[1], $w)), 2);
                if (abs($w - $w0) >= self::WIDEN_TOLERANCE) {
                    $adj['range_widen'][$type] = $w;
                    $reasons[] = "{$label[$type]}: ranges " . ($w > $w0 ? 'wider' : 'narrower') . ' (× ' . number_format($w, 2) . '). '
                        . $pct($s['coverage']) . " of measured results fell inside the estimated range, against about 80% intended ({$s['with_range']} measured).";
                }
            }
            if ($s['success_rate'] !== null) {
                $t0 = (int) $now['tier_shift'][$type];
                if ($s['success_rate'] < self::MIN_SUCCESS && $t0 === 0) {
                    $adj['tier_shift'][$type] = -1;
                    $reasons[] = "{$label[$type]}: one confidence tier lower. Only " . $pct($s['success_rate']) . " of measured " . mb_strtolower($label[$type]) . " worked ({$s['strong']} measured).";
                } elseif ($s['success_rate'] >= self::RESTORE_SUCCESS && $t0 !== 0) {
                    $adj['tier_shift'][$type] = 0;
                    $reasons[] = "{$label[$type]}: confidence back as built. " . $pct($s['success_rate']) . " of measured " . mb_strtolower($label[$type]) . " worked ({$s['strong']} measured).";
                }
            }
        }
        $d = $stats[AssortmentGap::TYPE_DELIST];
        if ($d['share_n'] >= self::MIN_MEASURED && $d['share_bias'] !== null) {
            $v = round(max(-self::SHARE_BOUND, min(self::SHARE_BOUND, $d['share_bias'])), 2);
            if (abs($v - (float) $now['share_shift']) >= self::SHARE_TOLERANCE) {
                $adj['share_shift'] = $v;
                $reasons[] = 'Transferable demand: ' . ($v >= 0 ? '+' : '−') . (int) round(abs($v) * 100) . ' points on the share of buyers who switch (was '
                    . ($now['share_shift'] >= 0 ? '+' : '−') . (int) round(abs((float) $now['share_shift']) * 100) . '). After measured delists, '
                    . ($d['share_bias'] >= 0 ? 'more' : 'fewer') . ' buyers moved to the rest of the shelf than estimated (median '
                    . ($d['share_bias'] >= 0 ? '+' : '−') . (int) round(abs($d['share_bias']) * 100) . " points, {$d['share_n']} measured).";
            }
        }

        if ($reasons === []) {
            return null;
        }
        $open = AssortmentCalibration::where('tenant_id', $tenantId)->where('status', AssortmentCalibration::STATUS_PROPOSED)->get();
        foreach ($open as $c) {
            if ($c->adjustments == $adj) {
                return $c;   // the same proposal is already waiting
            }
        }

        return DB::transaction(function () use ($tenantId, $open, $adj, $now, $reasons, $stats) {
            AssortmentCalibration::whereIn('id', $open->pluck('id'))->update(['status' => AssortmentCalibration::STATUS_SUPERSEDED, 'updated_at' => now()]);
            $prev = $now;
            unset($prev['version']);

            return AssortmentCalibration::create([
                'tenant_id' => $tenantId,
                'version' => (int) AssortmentCalibration::where('tenant_id', $tenantId)->max('version') + 1,
                'status' => AssortmentCalibration::STATUS_PROPOSED, 'adjustments' => $adj, 'previous' => $prev + ['version' => $now['version']],
                'reasons' => $reasons, 'basis' => $stats,
            ]);
        });
    }

    public function approve(AssortmentCalibration $cal, User $by, ?string $note = null): void
    {
        abort_unless($cal->status === AssortmentCalibration::STATUS_PROPOSED, 422, 'Only a proposed calibration can be approved.');
        DB::transaction(function () use ($cal, $by, $note) {
            AssortmentCalibration::where('tenant_id', $cal->tenant_id)->where('status', AssortmentCalibration::STATUS_APPROVED)
                ->update(['status' => AssortmentCalibration::STATUS_SUPERSEDED, 'updated_at' => now()]);
            $cal->forceFill(['status' => AssortmentCalibration::STATUS_APPROVED, 'decided_by' => $by->id, 'decided_at' => now(),
                'decision_note' => $note ? mb_substr(trim($note), 0, 500) : null])->save();
        });
        $this->audit($cal, $by, 'assortment.calibration_approved', 'Approved Assortment calibration ' . $cal->label() . ' — applies from the next run');
    }

    public function reject(AssortmentCalibration $cal, User $by, string $note): void
    {
        abort_unless($cal->status === AssortmentCalibration::STATUS_PROPOSED, 422, 'Only a proposed calibration can be rejected.');
        $cal->forceFill(['status' => AssortmentCalibration::STATUS_REJECTED, 'decided_by' => $by->id, 'decided_at' => now(),
            'decision_note' => mb_substr(trim($note), 0, 500)])->save();
        $this->audit($cal, $by, 'assortment.calibration_rejected', 'Rejected Assortment calibration ' . $cal->label());
    }

    /** The rules back as built (from the next run). */
    public function retire(Tenant $tenant, User $by): ?AssortmentCalibration
    {
        $cal = AssortmentCalibration::where('tenant_id', $tenant->id)->where('status', AssortmentCalibration::STATUS_APPROVED)->orderByDesc('version')->first();
        if ($cal === null) {
            return null;
        }
        $cal->forceFill(['status' => AssortmentCalibration::STATUS_RETIRED, 'decided_by' => $by->id, 'decided_at' => now()])->save();
        $this->audit($cal, $by, 'assortment.calibration_retired', 'Retired Assortment calibration ' . $cal->label() . ' — the rules run as built from the next run');

        return $cal;
    }

    private function audit(AssortmentCalibration $cal, User $by, string $event, string $summary): void
    {
        try {
            AuditLog::create(['tenant_id' => $cal->tenant_id, 'user_id' => $by->id, 'event_type' => $event, 'description' => mb_substr($summary, 0, 250),
                'new_value' => ['calibration' => $cal->version, 'adjustments' => $cal->adjustments], 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function median(array $v): float
    {
        sort($v);
        $n = count($v);

        return $n % 2 ? (float) $v[intdiv($n, 2)] : ((float) $v[$n / 2 - 1] + (float) $v[$n / 2]) / 2;
    }

    /**
     * v2.5 — every measured result against what was estimated (the Learning
     * scatter): one point per measured decision or plan, a year, in money.
     * Weak measurements (too few similar stores, or noisy) are drawn hollow.
     *
     * @return array{points: list<array>, n: int}
     */
    public function points(int $tenantId, int $limit = 600): array
    {
        $series = [AssortmentGap::TYPE_ADD => 1, AssortmentGap::TYPE_DELIST => 2, AssortmentGap::TYPE_STOCKOUT_HIDDEN => 3];
        $names = [AssortmentGap::TYPE_ADD => 'Add', AssortmentGap::TYPE_DELIST => 'Delist', AssortmentGap::TYPE_STOCKOUT_HIDDEN => 'Stockout fix'];
        $out = [];
        $gaps = AssortmentGap::with(['product:id,name', 'store:id,name'])->where('tenant_id', $tenantId)->whereNotNull('measured_at')
            ->whereIn('type', self::TYPES)->latest('measured_at')->limit($limit)->get(['id', 'tenant_id', 'sku', 'product_id', 'store_id', 'type', 'evidence', 'measurement']);
        foreach ($gaps as $g) {
            $m = (array) ($g->measurement ?? []);
            $e = (array) ($g->evidence ?? []);
            $actual = $m['uplift_per_year'] ?? null;
            $expected = $m['expected_per_year'] ?? $e['expected_sales_change_per_year'] ?? null;
            if ($actual === null || $expected === null) {
                continue;
            }
            $out[] = ['x' => round((float) $expected, 2), 'y' => round((float) $actual, 2), 's' => $series[$g->type],
                'hollow' => ($m['strength'] ?? 'weak') === 'weak',
                'what' => $names[$g->type] . ': ' . ($g->product?->name ?? $g->sku) . ($g->store ? ' at ' . $g->store->name : ''),
                'verdict' => (string) ($m['verdict'] ?? '')];
        }
        $plans = \App\Models\AssortmentPlan::with('store:id,name')->where('tenant_id', $tenantId)->whereNotNull('measured_at')
            ->latest('measured_at')->limit($limit)->get(['id', 'store_id', 'category', 'measurement']);
        foreach ($plans as $p) {
            $m = (array) ($p->measurement ?? []);
            if (! isset($m['uplift_per_year'], $m['expected_per_year'])) {
                continue;
            }
            $out[] = ['x' => round((float) $m['expected_per_year'], 2), 'y' => round((float) $m['uplift_per_year'], 2), 's' => 4,
                'hollow' => ($m['strength'] ?? 'weak') === 'weak',
                'what' => 'Range plan: ' . $p->category . ($p->store ? ' at ' . $p->store->name : ''), 'verdict' => (string) ($m['verdict'] ?? '')];
        }

        return ['points' => $out, 'n' => count($out)];
    }
}
