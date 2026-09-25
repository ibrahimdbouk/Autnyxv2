<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\User;
use App\Services\Sales\SalesDailyAggregator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WP6.6 (audit M24, D9) — every growing table has a window, open work is
 * never purged, aggregates outlive their purged raw lines, old import files
 * go, failed jobs and prunable models are pruned on a schedule.
 */
class RetentionTest extends TestCase
{
    public function test_the_new_windows_purge_old_rows_but_never_open_work(): void
    {
        $t = $this->createTenant();
        $old = now()->subDays(800)->toDateString();
        DB::table('sales_returns')->insert(['tenant_id' => $t->id, 'sku' => 'A', 'date' => $old, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['R' => now()->subDays(700)->toDateString(), 'O' => null] as $po => $received) {
            DB::table('purchase_orders')->insert(['tenant_id' => $t->id, 'po_number' => $po, 'supplier' => 'S', 'sku' => 'A', 'qty_ordered' => 1,
                'order_date' => $old, 'received_date' => $received, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['open', 'resolved'] as $status) {
            DB::table('quarantined_rows')->insert(['tenant_id' => $t->id, 'data_type' => 'sales_transactions', 'row_number' => 1,
                'raw_data' => '{}', 'reason_code' => 'x', 'severity' => 'error', 'status' => $status, 'created_at' => now()->subDays(200), 'updated_at' => now()]);
        }
        $user = $this->createUser($t);
        foreach ([now()->subDay(), null] as $read) {
            DB::table('notifications')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class,
                'notifiable_id' => $user->id, 'data' => '{}', 'read_at' => $read, 'created_at' => now()->subDays(200), 'updated_at' => now()]);
        }

        $this->artisan('data:purge')->assertSuccessful();

        $this->assertSame(0, DB::table('sales_returns')->where('tenant_id', $t->id)->count());
        $this->assertSame(['O'], DB::table('purchase_orders')->where('tenant_id', $t->id)->pluck('po_number')->all(), 'an open PO stays, however old');
        $this->assertSame(['open'], DB::table('quarantined_rows')->where('tenant_id', $t->id)->pluck('status')->all());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $user->id)->whereNull('read_at')->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $user->id)->whereNotNull('read_at')->count());
    }

    public function test_daily_aggregates_outlive_their_purged_lines(): void
    {
        $t = $this->createTenant();
        $store = \App\Models\Store::create(['tenant_id' => $t->id, 'name' => 'Mall']);
        $ancient = now()->subDays(500)->toDateString();   // raw lines kept 395 days, aggregates 550
        DB::table('sales_daily')->insert(['tenant_id' => $t->id, 'store_id' => $store->id, 'sku' => 'A', 'date' => $ancient,
            'units_sold' => 7, 'revenue' => 70, 'transaction_count' => 3, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales_transactions')->insert(['tenant_id' => $t->id, 'store_id' => $store->id, 'sku' => 'A', 'date' => now()->subDays(3)->toDateString(),
            'quantity' => 2, 'total_amount' => 20, 'created_at' => now(), 'updated_at' => now()]);

        $agg = app(SalesDailyAggregator::class);
        $agg->rebuildForTenant($t->id);
        $agg->aggregateRange($t->id, now()->subDays(600)->toDateString(), now()->toDateString());

        $this->assertSame(7.0, (float) DB::table('sales_daily')->where('tenant_id', $t->id)->where('date', $ancient)->value('units_sold'));
        $this->assertSame(2.0, (float) DB::table('sales_daily')->where('tenant_id', $t->id)->where('date', now()->subDays(3)->toDateString())->value('units_sold'));
    }

    public function test_old_import_files_are_deleted_and_the_rows_kept(): void
    {
        Storage::fake('local');
        $t = $this->createTenant();
        Storage::disk('local')->put('imports/old.csv', 'x');
        Storage::disk('local')->put('imports/new.csv', 'x');
        $old = Import::create(['tenant_id' => $t->id, 'original_filename' => 'old.csv', 'disk' => 'local', 'path' => 'imports/old.csv',
            'data_type' => Import::TYPE_SALES, 'status' => Import::STATUS_COMPLETED]);
        $old->forceFill(['created_at' => now()->subDays(120)])->save();
        Import::create(['tenant_id' => $t->id, 'original_filename' => 'new.csv', 'disk' => 'local', 'path' => 'imports/new.csv',
            'data_type' => Import::TYPE_SALES, 'status' => Import::STATUS_COMPLETED]);

        $this->artisan('data:purge')->assertSuccessful();

        Storage::disk('local')->assertMissing('imports/old.csv');
        Storage::disk('local')->assertExists('imports/new.csv');
        $this->assertNotNull($old->fresh()->file_purged_at);
    }

    public function test_failed_jobs_and_prunable_models_are_pruned_on_a_schedule(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");

        $this->assertStringContainsString('queue:prune-failed', $commands);
        $this->assertStringContainsString('model:prune', $commands);
    }
}
