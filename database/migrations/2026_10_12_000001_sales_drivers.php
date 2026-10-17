<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Root Cause — cross-metric drivers (phase 1 of
 * claude/root-cause-drivers-and-expected-scope.md).
 *
 *  store_daily     one row per store and day: receipts (store traffic),
 *                  lines, units, revenue, SKUs sold. sales_daily counts the
 *                  receipts that contain a SKU, which cannot be added up into
 *                  a store's traffic; this can.
 *  category_daily  one row per store, department, category and day: units,
 *                  revenue, SKUs sold, and the receipts that contain the
 *                  category (its penetration).
 *  store_traffic   the optional door-counter import: visitors per store and
 *                  day, for visitors × conversion instead of receipts alone.
 *  driver_analyses the stored breakdowns (what moved and why), per scope and
 *                  pair of periods.
 *
 * Receipts are null where the sales lines carry no receipt id (traffic then
 * cannot be told apart from basket size).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('store_daily', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('store_id');
            $t->date('date');
            $t->unsignedInteger('receipts')->nullable();
            $t->unsignedInteger('lines')->default(0);
            $t->unsignedInteger('lines_with_receipt')->default(0);
            $t->decimal('units', 16, 4)->default(0);
            $t->decimal('revenue', 18, 4)->default(0);
            $t->unsignedInteger('skus_sold')->default(0);
            $t->timestamps();

            $t->unique(['tenant_id', 'store_id', 'date']);
            $t->index(['tenant_id', 'date']);
        });

        Schema::create('category_daily', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('store_id');
            $t->string('department', 120)->default('');
            $t->string('category', 120)->default('');
            $t->date('date');
            $t->unsignedInteger('receipts')->nullable();
            $t->decimal('units', 16, 4)->default(0);
            $t->decimal('revenue', 18, 4)->default(0);
            $t->unsignedInteger('skus_sold')->default(0);
            $t->timestamps();

            $t->unique(['tenant_id', 'store_id', 'department', 'category', 'date'], 'category_daily_unique');
            $t->index(['tenant_id', 'date']);
            $t->index(['tenant_id', 'department', 'date']);
        });

        Schema::create('store_traffic', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('import_id')->nullable()->index();
            $t->unsignedBigInteger('store_id');
            $t->string('location')->nullable();
            $t->date('date');
            $t->decimal('visitors', 14, 2);
            $t->timestamps();

            $t->index(['tenant_id', 'date']);
        });
        \App\Support\Database\ConcurrentIndex::create('store_traffic_natural_key', 'store_traffic', '(tenant_id, store_id, date)', unique: true);

        Schema::create('driver_analyses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('scope_key', 200);                 // business | store:12 | dept:Grocery | store:12|dept:Grocery
            $t->string('kind', 12)->default('weekly');    // weekly | custom
            $t->date('period_from');
            $t->date('period_to');
            $t->date('comparison_from');
            $t->date('comparison_to');
            $t->string('comparison_kind', 12);            // yoy | prior4w | custom
            $t->decimal('change', 18, 4)->default(0);
            $t->decimal('unexplained', 18, 4)->default(0);
            $t->jsonb('result');
            $t->string('version', 20);
            $t->timestamp('computed_at');
            $t->timestamps();

            $t->unique(['tenant_id', 'scope_key', 'period_from', 'period_to', 'comparison_from', 'comparison_to'], 'driver_analyses_unique');
            $t->index(['tenant_id', 'kind', 'period_to']);
        });

        // How an investigation's sales findings are explained: availability
        // (mostly out-of-stock days) or calendar (promotions / events).
        Schema::table('investigations', function (Blueprint $t) {
            $t->string('drivers_explained', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('investigations', fn (Blueprint $t) => $t->dropColumn('drivers_explained'));
        Schema::dropIfExists('driver_analyses');
        Schema::dropIfExists('store_traffic');
        Schema::dropIfExists('category_daily');
        Schema::dropIfExists('store_daily');
    }
};
