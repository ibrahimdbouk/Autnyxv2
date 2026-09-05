<?php

namespace App\Console\Commands;

use App\Models\CalendarNode;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * P1.1 — generate the canonical Calendar dimension (year → quarter → month → day)
 * for one or all tenants. Idempotent: every node is firstOrCreate'd on
 * (tenant, type, code), so re-running only fills gaps / extends the range.
 * Default window is 2 years back to 1 year forward, covering history + horizon.
 */
class BuildCalendarCommand extends Command
{
    protected $signature = 'calendar:build
        {--tenant= : Tenant id (default: all tenants)}
        {--from= : Start date Y-m-d (default: 2 years ago, start of year)}
        {--to= : End date Y-m-d (default: 1 year ahead, end of year)}';

    protected $description = 'Generate the canonical Calendar / time dimension (year→quarter→month→day).';

    public function handle(): int
    {
        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))->startOfDay()
            : Carbon::now()->subYears(2)->startOfYear();
        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))->startOfDay()
            : Carbon::now()->addYear()->endOfYear();

        if ($from->gt($to)) {
            $this->error('--from must be on or before --to.');
            return self::FAILURE;
        }

        $tenantIds = $this->option('tenant')
            ? [(int) $this->option('tenant')]
            : Tenant::query()->pluck('id')->all();

        $totalDays = 0;
        foreach ($tenantIds as $tenantId) {
            $totalDays += $this->buildForTenant($tenantId, $from->copy(), $to->copy());
        }

        $this->info('Calendar built for ' . count($tenantIds) . ' tenant(s): ' . $totalDays . ' day node(s) ensured.');

        return self::SUCCESS;
    }

    private function buildForTenant(int $tenantId, Carbon $from, Carbon $to): int
    {
        $yearId = [];
        $quarterId = [];
        $monthId = [];
        $days = 0;

        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $y = $cursor->year;
            $q = $cursor->quarter;
            $m = $cursor->month;

            $yCode = (string) $y;
            $qCode = $y . '-Q' . $q;
            $mCode = sprintf('%d-%02d', $y, $m);

            if (! isset($yearId[$yCode])) {
                $yearId[$yCode] = CalendarNode::firstOrCreate(
                    ['tenant_id' => $tenantId, 'type' => CalendarNode::TYPE_YEAR, 'code' => $yCode],
                    ['name' => $yCode, 'parent_id' => null],
                )->id;
            }

            if (! isset($quarterId[$qCode])) {
                $quarterId[$qCode] = CalendarNode::firstOrCreate(
                    ['tenant_id' => $tenantId, 'type' => CalendarNode::TYPE_QUARTER, 'code' => $qCode],
                    ['name' => 'Q' . $q . ' ' . $y, 'parent_id' => $yearId[$yCode]],
                )->id;
            }

            if (! isset($monthId[$mCode])) {
                $monthId[$mCode] = CalendarNode::firstOrCreate(
                    ['tenant_id' => $tenantId, 'type' => CalendarNode::TYPE_MONTH, 'code' => $mCode],
                    ['name' => $cursor->format('M Y'), 'parent_id' => $quarterId[$qCode]],
                )->id;
            }

            CalendarNode::firstOrCreate(
                ['tenant_id' => $tenantId, 'type' => CalendarNode::TYPE_DAY, 'code' => $cursor->format('Y-m-d')],
                [
                    'name'       => $cursor->format('D, d M Y'),
                    'parent_id'  => $monthId[$mCode],
                    'date'       => $cursor->format('Y-m-d'),
                    'attributes' => [
                        'iso_week'    => $cursor->isoWeek(),
                        'day_of_week' => $cursor->dayOfWeekIso,
                        'is_weekend'  => $cursor->isWeekend(),
                    ],
                ],
            );
            $days++;
        }

        return $days;
    }
}
