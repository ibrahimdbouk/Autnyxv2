<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Assortment\OutcomeMeasurer;
use Illuminate\Console\Command;

/**
 * Assortment A5 — measure accepted range decisions whose task was done at
 * least `measure_days` ago, against peer stores that did not make the change.
 */
class MeasureAssortmentCommand extends Command
{
    protected $signature = 'assortment:measure {--tenant= : Only this tenant id}';

    protected $description = 'Measure completed Assortment decisions against peer stores (difference-in-differences).';

    public function handle(OutcomeMeasurer $measurer): int
    {
        $tenants = Tenant::query()->where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')->get();

        foreach ($tenants as $tenant) {
            if (! $tenant->hasApp(Tenant::APP_ASSORTMENT)) {
                continue;
            }
            $r = $measurer->measureDue((int) $tenant->id);
            if ($r['measured'] || $r['waiting']) {
                $this->info("#{$tenant->id} {$tenant->name}: measured {$r['measured']}, waiting for data {$r['waiting']}");
            }
        }

        return self::SUCCESS;
    }
}
