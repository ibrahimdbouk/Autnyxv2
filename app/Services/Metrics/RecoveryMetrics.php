<?php

namespace App\Services\Metrics;

use App\Models\InvestigationOutcome;
use App\Services\Recovery\AnomalyRecoveryService;
use App\Support\Tenancy\TenantClock;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * WP7.1 (audit H33) — one definition of "recovered this month", used by the
 * dashboard KPI, its drill-down (Financial Breakdown), the operations pulse
 * and the charts. There were three: outcomes by created_at on the server's
 * UTC month, by created_at on the tenant's month, and by recorded_at on the
 * UTC month; the trend compared a part-month with the whole previous month.
 *
 * ATTRIBUTED recovery: Σ observed_recovery (> 0) over the tenant's
 * investigation outcomes, dated by `recorded_at` — when the recovery was
 * measured or entered (re-measuring an outcome moves it). Month to date is
 * the tenant's month; the comparison is the same stretch of last month.
 *
 * OBSERVED recovery (anomalies that cleared and stayed clear) comes from
 * AnomalyRecoveryService on the same periods. The two are never summed.
 */
class RecoveryMetrics
{
    public function __construct(private AnomalyRecoveryService $observed) {}

    /** Outcomes counted as attributed recovery in [from, to). */
    public function attributedQuery(int $tenantId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): Builder
    {
        $appTz = config('app.timezone', 'UTC');

        // W10: MEASURED recovery only — a figure a person typed in is a claim
        // (see claimed()), never counted as recovered.
        return InvestigationOutcome::query()
            ->where('tenant_id', $tenantId)
            ->where('measured_recovery', '>', 0)
            ->when($from, fn ($q) => $q->where('recorded_at', '>=', Carbon::instance($from)->setTimezone($appTz)))
            ->when($to, fn ($q) => $q->where('recorded_at', '<', Carbon::instance($to)->setTimezone($appTz)));
    }

    /** @return array{amount: float, count: int} */
    public function attributed(int $tenantId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $row = $this->attributedQuery($tenantId, $from, $to)
            ->toBase()
            ->selectRaw('COALESCE(SUM(measured_recovery), 0) AS amount, COUNT(*) AS cnt')
            ->first();

        return ['amount' => (float) ($row->amount ?? 0), 'count' => (int) ($row->cnt ?? 0)];
    }

    /** W10: outcomes with a recovery figure a person entered and no measurement confirmed. */
    public function claimedQuery(int $tenantId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): Builder
    {
        $appTz = config('app.timezone', 'UTC');

        return InvestigationOutcome::query()
            ->where('tenant_id', $tenantId)
            ->where('observed_recovery', '>', 0)
            ->whereNull('measured_recovery')
            ->when($from, fn ($q) => $q->where('recorded_at', '>=', Carbon::instance($from)->setTimezone($appTz)))
            ->when($to, fn ($q) => $q->where('recorded_at', '<', Carbon::instance($to)->setTimezone($appTz)));
    }

    /** @return array{amount: float, count: int} */
    public function claimed(int $tenantId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $row = $this->claimedQuery($tenantId, $from, $to)->toBase()
            ->selectRaw('COALESCE(SUM(observed_recovery), 0) AS amount, COUNT(*) AS cnt')->first();

        return ['amount' => (float) ($row->amount ?? 0), 'count' => (int) ($row->cnt ?? 0)];
    }

    public function monthStart(int $tenantId): Carbon
    {
        return TenantClock::startOfMonth($tenantId);
    }

    /** @return array{amount: float, count: int} attributed, this month to date */
    public function attributedMtd(int $tenantId): array
    {
        return $this->attributed($tenantId, $this->monthStart($tenantId));
    }

    /** @return array{amount: float, count: int} attributed, same stretch of last month */
    public function attributedPrevMtd(int $tenantId): array
    {
        [$from, $to] = TenantClock::samePeriodLastMonth($tenantId);

        return $this->attributed($tenantId, $from, $to);
    }

    /**
     * Every month-to-date recovery figure the dashboard shows.
     *
     * @return array{attributed: float, attributed_prev: float, attributed_count: int, observed: float, observed_prev: float, observed_count: int}
     */
    public function summary(int $tenantId): array
    {
        $a  = $this->attributedMtd($tenantId);
        $ap = $this->attributedPrevMtd($tenantId);
        $o  = $this->observed->mtd($tenantId);
        $op = $this->observed->prevMtd($tenantId);
        $c  = $this->claimed($tenantId, $this->monthStart($tenantId));

        return [
            'claimed'          => $c['amount'],
            'claimed_count'    => $c['count'],
            'attributed'       => $a['amount'],
            'attributed_prev'  => $ap['amount'],
            'attributed_count' => $a['count'],
            'observed'         => (float) $o['amount'],
            'observed_prev'    => (float) $op['amount'],
            'observed_count'   => (int) $o['count'],
        ];
    }

    /**
     * Attributed recovery per tenant-local day for the last $days days.
     *
     * @return array<string,float> 'Y-m-d' → amount
     */
    public function attributedDaily(int $tenantId, int $days = 30): array
    {
        $day = TenantClock::localDateSql('recorded_at', $tenantId);

        return $this->attributedQuery($tenantId, TenantClock::today($tenantId)->subDays($days - 1))
            ->toBase()
            ->selectRaw("TO_CHAR({$day}, 'YYYY-MM-DD') AS d, SUM(measured_recovery) AS total")
            ->groupByRaw("TO_CHAR({$day}, 'YYYY-MM-DD')")
            ->pluck('total', 'd')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /** @return array<string,float> observed (cleared) per tenant-local day */
    public function observedDaily(int $tenantId, int $days = 30): array
    {
        return $this->observed->dailySeries($tenantId, $days);
    }
}
