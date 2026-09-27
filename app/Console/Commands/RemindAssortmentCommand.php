<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Assortment\AssortmentNotifier;
use App\Services\Assortment\TenantAssortment;
use Illuminate\Console\Command;

/**
 * v1 gap 1 — remind the owners of accepted range tasks, once when due within
 * two days and once when overdue. Live tenants only (nothing is assigned before).
 */
class RemindAssortmentCommand extends Command
{
    protected $signature = 'assortment:remind {--tenant= : Only this tenant id}';

    protected $description = 'Remind owners of Assortment tasks that are due soon or overdue (once each).';

    public function handle(AssortmentNotifier $notifier): int
    {
        $tenants = Tenant::query()->where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')->get();

        foreach ($tenants as $tenant) {
            if (! $tenant->hasApp(Tenant::APP_ASSORTMENT) || ! TenantAssortment::isLive($tenant)) {
                continue;
            }
            $r = $notifier->remind((int) $tenant->id);
            if ($r['due_soon'] || $r['overdue']) {
                $this->info("#{$tenant->id} {$tenant->name}: due soon {$r['due_soon']}, overdue {$r['overdue']}");
            }
        }

        return self::SUCCESS;
    }
}
