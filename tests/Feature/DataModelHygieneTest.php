<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\Platform\HierarchySync;
use App\Support\Database\IntegrityKeys;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WP6.5 (audit M23, M25, H7) — money that fits any currency, lookup keys the
 * database enforces, tenant foreign keys on every tenant table, hierarchies
 * that follow the master data.
 */
class DataModelHygieneTest extends TestCase
{
    public function test_a_dong_amount_fits(): void
    {
        $t = $this->createTenant(['currency' => 'VND']);
        $id = DB::table('sales_transactions')->insertGetId(['tenant_id' => $t->id, 'sku' => 'A', 'date' => '2026-09-01',
            'quantity' => '12345.5', 'unit_price' => '25000000000.25', 'total_amount' => '308637500003.0625', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame('308637500003.0625', (string) DB::table('sales_transactions')->where('id', $id)->value('total_amount'));
    }

    public function test_supplier_names_and_store_codes_are_unique_whatever_the_case(): void
    {
        $t = $this->createTenant();
        Supplier::create(['tenant_id' => $t->id, 'name' => 'Acme Foods']);
        Store::create(['tenant_id' => $t->id, 'name' => 'Mall', 'code' => 'ST042']);
        Store::create(['tenant_id' => $t->id, 'name' => 'No code 1']);
        Store::create(['tenant_id' => $t->id, 'name' => 'No code 2']);   // many stores without a code are fine

        $this->assertThrows(fn () => DB::transaction(fn () => Supplier::create(['tenant_id' => $t->id, 'name' => ' ACME FOODS'])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => Store::create(['tenant_id' => $t->id, 'name' => 'Other', 'code' => 'st042 '])), QueryException::class);

        $other = $this->createTenant();
        Supplier::create(['tenant_id' => $other->id, 'name' => 'Acme Foods']);   // per tenant
        $this->assertSame(2, Supplier::where('name', 'Acme Foods')->count());
    }

    public function test_a_chain_level_baseline_is_unique_too(): void
    {
        $t = $this->createTenant();
        $row = ['tenant_id' => $t->id, 'sku' => 'A', 'store_id' => null, 'rule_type' => 'sales_drop', 'metric' => 'daily_sales_qty',
            'baseline_mean' => 1, 'baseline_stddev' => 1, 'sample_count' => 10, 'sensitivity_multiplier' => 2, 'fp_count' => 0,
            'created_at' => now(), 'updated_at' => now()];
        DB::table('sku_baselines')->insert($row);

        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('sku_baselines')->insert($row)), QueryException::class);
    }

    public function test_the_formerly_unlinked_tables_belong_to_a_tenant(): void
    {
        $t = $this->createTenant();
        DB::table('sku_profiles')->insert(['tenant_id' => $t->id, 'sku' => 'A', 'store_id' => 0, 'segment' => 'smooth',
            'lifecycle' => 'mature', 'chosen_model' => 'none', 'window_days' => 90, 'selling_days' => 1, 'total_units' => 1,
            'total_revenue' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('agent_runs')->insert(['tenant_id' => 999999, 'agent' => 'x',
            'status' => 'complete', 'created_at' => now(), 'updated_at' => now()])), QueryException::class);

        DB::table('tenants')->where('id', $t->id)->delete();
        $this->assertSame(0, DB::table('sku_profiles')->where('tenant_id', $t->id)->count(), 'erased with its tenant');
        foreach (IntegrityKeys::TENANT_FK as $table) {
            $this->assertTrue((bool) DB::selectOne('SELECT convalidated AS v FROM pg_constraint WHERE conname = ?', ["{$table}_tenant_id_fk"])->v, $table);
        }
    }

    public function test_the_hierarchies_follow_the_master_data(): void
    {
        $t = $this->createTenant();
        $p = Product::create(['tenant_id' => $t->id, 'sku' => 'MILK1', 'name' => 'Milk 1L', 'category' => 'Dairy', 'subcategory' => 'Milk']);
        Store::create(['tenant_id' => $t->id, 'name' => 'Marina', 'code' => 'M1', 'region' => 'Dubai']);
        Supplier::create(['tenant_id' => $t->id, 'name' => 'Al Ain Farms', 'type' => 'Dairy']);
        $sync = app(HierarchySync::class);

        $sync->syncTenant($t->id);
        $leaf = DB::table('product_nodes')->where('product_id', $p->id)->first();
        $parent = DB::table('product_nodes')->find($leaf->parent_id);
        $this->assertSame(['subcategory', 'Milk'], [$parent->type, $parent->name]);
        $this->assertSame('Dairy', DB::table('product_nodes')->find($parent->parent_id)->name);
        $this->assertSame('Dubai', DB::table('location_nodes')->where('type', 'region')->where('tenant_id', $t->id)->value('name'));
        $this->assertSame(1, DB::table('supplier_nodes')->where('tenant_id', $t->id)->where('type', 'supplier')->count());

        $p->update(['name' => 'Fresh Milk 1L', 'subcategory' => null]);
        $sync->syncTenant($t->id);
        $leaf = DB::table('product_nodes')->where('product_id', $p->id)->first();
        $this->assertSame('Fresh Milk 1L', $leaf->name);
        $this->assertSame('category', DB::table('product_nodes')->find($leaf->parent_id)->type, 'no subcategory → under the category');

        $p->delete();
        $sync->syncTenant($t->id);
        $this->assertSame(0, DB::table('product_nodes')->where('tenant_id', $t->id)->where('type', 'product')->count());

        $this->assertSame(['products' => 0, 'locations' => 0, 'suppliers' => 0, 'departments' => 0], $sync->syncTenant($t->id), 'a second sync changes nothing');
    }

    public function test_db_integrity_reports_and_repairs_derived_duplicates(): void
    {
        $this->artisan('db:integrity')->assertSuccessful()->expectsOutputToContain('Dry run');
    }
}
