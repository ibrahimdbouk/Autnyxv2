<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGap;
use App\Models\AssortmentPlan;
use App\Models\AssortmentRun;
use App\Models\Tenant;
use App\Platform\Governance\MeteringService;
use Carbon\Carbon;

/**
 * v2.1 — what each tenant uses of Assortment Intelligence, per month, on the
 * platform's usage meters (the basis for pricing).
 *
 * Counts are REBUILT from the app's own records, never incremented as things
 * happen: a month's figures always match its records, and running the rebuild
 * twice gives the same numbers. Months are calendar months in UTC.
 */
class AssortmentUsage
{
    public const APP = Tenant::APP_ASSORTMENT;

    /** metric => what it counts (shown on the Ops usage page). */
    public const METRICS = [
        'runs'               => 'Assortment runs that finished',
        'stores'             => 'Stores judged (most in one run)',
        'decisions_proposed' => 'Range decisions proposed',
        'decisions_accepted' => 'Range decisions accepted',
        'plans_accepted'     => 'Range plans accepted',
        'studio_plans'       => 'Plans made in the Decision Studio',
        'results_measured'   => 'Results measured (decisions and plans)',
        'briefings'          => 'Weekly briefings built',
        'new_ranges'         => 'New-store and remodel ranges built',
        'programmes'         => 'Tail clean-up programmes accepted',
    ];

    public function __construct(private MeteringService $meters) {}

    /** @return array<string,int> metric => count, as written to the meters */
    public function rebuild(int $tenantId, ?string $period = null): array
    {
        $period ??= now()->format('Y-m');
        $counts = $this->count($tenantId, $period);
        foreach ($counts as $metric => $n) {
            $this->meters->set($tenantId, self::APP, $metric, $n, $period);
        }

        return $counts;
    }

    /** @return array<string,int> */
    public function count(int $tenantId, string $period): array
    {
        $from = Carbon::createFromFormat('Y-m-d H:i:s', $period . '-01 00:00:00', 'UTC');
        $to = $from->copy()->addMonth();
        $in = fn ($q, string $col) => $q->where($col, '>=', $from)->where($col, '<', $to);

        $runs = $in(AssortmentRun::where('tenant_id', $tenantId)->where('status', AssortmentRun::STATUS_SUCCESS), 'created_at')->get(['stats']);
        $gaps = AssortmentGap::where('tenant_id', $tenantId)->where('status', '<>', AssortmentGap::STATUS_SHADOW);
        $plans = AssortmentPlan::where('tenant_id', $tenantId);

        return [
            'runs'               => $runs->count(),
            'stores'             => (int) $runs->map(fn ($r) => (int) ($r->stats['range']['stores'] ?? 0))->max(),
            'decisions_proposed' => $in(clone $gaps, 'created_at')->count(),
            'decisions_accepted' => $in((clone $gaps)->where('status', AssortmentGap::STATUS_ACCEPTED), 'decided_at')->count(),
            'plans_accepted'     => $in((clone $plans)->whereIn('status', [AssortmentPlan::STATUS_ACCEPTED, AssortmentPlan::STATUS_IN_PROGRESS, AssortmentPlan::STATUS_MEASURED]), 'decided_at')->count(),
            'studio_plans'       => $in((clone $plans)->where('source', AssortmentPlan::SOURCE_STUDIO), 'created_at')->count(),
            'results_measured'   => $in(clone $gaps, 'measured_at')->count() + $in(clone $plans, 'measured_at')->count(),
            'briefings'          => $in(\App\Models\AssortmentBriefing::where('tenant_id', $tenantId), 'created_at')->count(),
            'new_ranges'         => $in(\App\Models\AssortmentNewRange::where('tenant_id', $tenantId), 'created_at')->count(),
            'programmes'         => $in(\App\Models\AssortmentProgramme::where('tenant_id', $tenantId)->whereNotNull('decided_at')
                ->whereIn('status', [\App\Models\AssortmentProgramme::ACCEPTED, \App\Models\AssortmentProgramme::DONE]), 'decided_at')->count(),
        ];
    }
}
