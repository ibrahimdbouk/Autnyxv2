<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Inventory\InventoryCurrentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * WP6.2 — recompute inventory_current (current position per store × SKU, lots
 * summed) from the inventory_levels history. Idempotent; safe at any time.
 */
class RebuildInventoryCurrentCommand extends Command
{
    protected $signature = 'inventory:rebuild-current {--tenant= : One tenant id (default: all)}';

    protected $description = 'Rebuild the current stock positions from the inventory history';

    public function handle(InventoryCurrentService $service): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->pluck('name', 'id');

        $rows = [];
        foreach ($tenants as $id => $name) {
            $history = DB::table('inventory_levels')->where('tenant_id', $id)->count();
            $positions = $service->rebuild((int) $id);
            $lots = (int) DB::table('inventory_current')->where('tenant_id', $id)->where('lots', '>', 1)->count();
            $rows[] = [$id, $name, $history, $positions, $lots];
        }

        $this->table(['Tenant', 'Name', 'History rows', 'Current positions', 'Positions with >1 lot'], $rows);

        return self::SUCCESS;
    }
}
