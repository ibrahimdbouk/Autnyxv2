<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP3.1 (audit C2) — a sales row is a receipt LINE, not a receipt.
 *
 * The old UNIQUE (tenant_id, transaction_id) meant every multi-line receipt
 * kept only one line: the bulk path failed the extra lines, the per-row path
 * overwrote them. Identity becomes (tenant_id, transaction_id, line_no).
 *
 * Big-table rules: no transaction, short lock_timeout for the ALTERs, the new
 * index is built CONCURRENTLY before the old constraint is dropped. Existing
 * rows keep line_no NULL (each receipt had one row) — NULLs never conflict.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'sales_tx_receipt_line_unique';

    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        if ($pgsql) {
            DB::statement("SET lock_timeout = '5s'");
        }

        Schema::table('sales_transactions', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_transactions', 'line_no')) {
                $t->unsignedInteger('line_no')->nullable();
            }
            if (! Schema::hasColumn('sales_transactions', 'row_hash')) {
                $t->string('row_hash', 64)->nullable();
            }
        });

        if (! Schema::hasColumn('imports', 'duplicate_rows')) {
            Schema::table('imports', fn (Blueprint $t) => $t->unsignedInteger('duplicate_rows')->default(0));
        }

        // Per-import receipt line counters, so derived line numbers continue
        // correctly when a receipt spans two processing chunks.
        if (! Schema::hasTable('import_line_counters')) {
            Schema::create('import_line_counters', function (Blueprint $t) {
                $t->foreignId('import_id')->constrained()->cascadeOnDelete();
                $t->string('transaction_id');
                $t->unsignedInteger('last_line');
                $t->primary(['import_id', 'transaction_id']);
            });
        }

        if ($pgsql) {
            // A failed CONCURRENTLY build leaves an INVALID index behind; drop it
            // so IF NOT EXISTS doesn't silently keep the broken one.
            $invalid = DB::selectOne(
                "select 1 as x from pg_class c join pg_index i on i.indexrelid = c.oid
                 where c.relname = ? and not i.indisvalid",
                [self::INDEX]
            );
            if ($invalid) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::INDEX);
            }

            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS ' . self::INDEX
                . ' ON sales_transactions (tenant_id, transaction_id, line_no) WHERE transaction_id IS NOT NULL');

            DB::statement('ALTER TABLE sales_transactions DROP CONSTRAINT IF EXISTS unique_tenant_transaction');
            DB::statement('DROP INDEX IF EXISTS unique_tenant_transaction');
            DB::statement('RESET lock_timeout');
        } else {
            Schema::table('sales_transactions', function (Blueprint $t) {
                $t->dropUnique('unique_tenant_transaction');
                $t->unique(['tenant_id', 'transaction_id', 'line_no'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        // The old one-row-per-receipt constraint is NOT restored: multi-line
        // receipts loaded after this migration would violate it.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::INDEX);
        }
        Schema::dropIfExists('import_line_counters');
        Schema::table('sales_transactions', function (Blueprint $t) {
            $t->dropColumn(['line_no', 'row_hash']);
        });
        Schema::table('imports', fn (Blueprint $t) => $t->dropColumn('duplicate_rows'));
    }
};
