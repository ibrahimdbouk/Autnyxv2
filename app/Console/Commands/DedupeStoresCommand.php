<?php

namespace App\Console\Commands;

use App\Models\Anomaly;
use App\Models\InventoryLevel;
use App\Models\PurchaseOrder;
use App\Models\SalesReturn;
use App\Models\SalesTransaction;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Sales\SalesDailyAggregator;
use Illuminate\Console\Command;

/**
 * Merges duplicate store records created before resolveStore matched on code.
 *
 * A transactions file that identifies stores by code ("ST042") used to create a
 * second, bare store (name "ST042", no code) instead of linking to the master
 * store (name "Fujairah Grocery 42", code "ST042"). This re-points all
 * transactional rows from each such duplicate onto the real store, deletes the
 * duplicate, and rebuilds the sales_daily aggregate so everything downstream
 * (cannibalization, store outliers, stockouts) sees one clean store per outlet.
 */
class DedupeStoresCommand extends Command
{
    protected $signature = 'stores:dedupe {--tenant=}';

    protected $description = 'Merge duplicate stores (bare code-named copies) into their master store record';

    public function handle(SalesDailyAggregator $aggregator): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::where('id', $this->option('tenant'))->get()
            : Tenant::all();

        foreach ($tenants as $tenant) {
            $merged = 0;
            $rowsMoved = 0;

            // Master stores are those with a code set.
            foreach (Store::where('tenant_id', $tenant->id)->whereNotNull('code')->get() as $real) {
                // A duplicate is a *different* store whose name equals this store's code.
                $dupes = Store::where('tenant_id', $tenant->id)
                    ->where('name', $real->code)
                    ->where('id', '!=', $real->id)
                    ->get();

                foreach ($dupes as $dupe) {
                    $rowsMoved += SalesTransaction::where('store_id', $dupe->id)->update(['store_id' => $real->id]);
                    $rowsMoved += InventoryLevel::where('store_id', $dupe->id)->update(['store_id' => $real->id]);
                    $rowsMoved += PurchaseOrder::where('store_id', $dupe->id)->update(['store_id' => $real->id]);
                    $rowsMoved += SalesReturn::where('store_id', $dupe->id)->update(['store_id' => $real->id]);

                    // Anomalies referencing the duplicate are re-detected cleanly; drop them.
                    Anomaly::where('store_id', $dupe->id)->delete();

                    $dupe->delete();
                    $merged++;
                }
            }

            // Rebuild the daily aggregate against the corrected store ids.
            $aggregator->rebuildForTenant($tenant->id);

            $this->info("Tenant {$tenant->id}: merged {$merged} duplicate store(s), moved {$rowsMoved} rows, rebuilt sales_daily.");
        }

        return Command::SUCCESS;
    }
}
