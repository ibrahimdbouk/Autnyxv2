<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Platform\HierarchySync;
use Illuminate\Console\Command;

/** WP6.5 — bring the product / location / supplier hierarchies in step with the master tables. */
class SyncHierarchyCommand extends Command
{
    protected $signature = 'hierarchy:sync {--tenant= : One tenant id (default: every active tenant)}';

    protected $description = 'Sync the canonical hierarchies with products, stores and suppliers';

    public function handle(HierarchySync $sync): int
    {
        $ids = $this->option('tenant')
            ? [(int) $this->option('tenant')]
            : Tenant::where('status', Tenant::STATUS_ACTIVE)->pluck('id')->all();

        $failures = 0;
        foreach ($ids as $id) {
            try {
                $n = $sync->syncTenant($id);
                $this->line("Tenant {$id}: " . json_encode($n));
            } catch (\Throwable $e) {
                $failures++;
                report($e);
                $this->error("Tenant {$id}: {$e->getMessage()}");
            }
        }

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
