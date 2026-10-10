<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Assortment\AssortmentUsage;
use Illuminate\Console\Command;

/**
 * v2.1 — rebuild the Assortment usage meters from the records, for this month
 * (and last month in the first days of a new one, so late records land), or
 * for the month given.
 */
class AssortmentUsageCommand extends Command
{
    protected $signature = 'assortment:usage {--tenant= : Only this tenant id} {--period= : A month, YYYY-MM}';

    protected $description = 'Rebuild Assortment usage meters (per tenant per month) from the records.';

    public function handle(AssortmentUsage $usage): int
    {
        $period = $this->option('period');
        if ($period !== null && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $period)) {
            $this->error('The period is a month: YYYY-MM.');

            return self::FAILURE;
        }
        $periods = $period ? [$period] : array_values(array_unique([now()->format('Y-m'), now()->day <= 3 ? now()->subMonthNoOverflow()->format('Y-m') : now()->format('Y-m')]));

        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey((int) $id))->orderBy('id')->get();
        foreach ($tenants as $tenant) {
            if (! $tenant->hasApp(Tenant::APP_ASSORTMENT)) {
                continue;
            }
            foreach ($periods as $p) {
                $c = $usage->rebuild((int) $tenant->id, $p);
                $this->line("#{$tenant->id} {$tenant->name} {$p}: " . collect($c)->map(fn ($n, $k) => "{$k} {$n}")->implode(', '));
            }
        }

        return self::SUCCESS;
    }
}
