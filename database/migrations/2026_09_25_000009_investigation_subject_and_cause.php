<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP4.5 (audit H22, H23) — an investigation's subject (what it is about: a
 * store + SKU, a SKU, a supplier, a PO, a store) for correlation by subject,
 * and the deterministic root-cause tier the narrator phrases and the KPIs use.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET lock_timeout = '5s'");
        }
        Schema::table('investigations', function (Blueprint $t) {
            if (! Schema::hasColumn('investigations', 'subject_key')) {
                $t->string('subject_key', 191)->nullable();
                $t->index(['tenant_id', 'subject_key'], 'investigations_tenant_subject_idx');
            }
            if (! Schema::hasColumn('investigations', 'root_cause_tier')) {
                $t->string('root_cause_tier', 16)->nullable();
                $t->string('root_cause_rule', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('investigations', function (Blueprint $t) {
            if (Schema::hasColumn('investigations', 'subject_key')) {
                $t->dropIndex('investigations_tenant_subject_idx');
                $t->dropColumn('subject_key');
            }
            if (Schema::hasColumn('investigations', 'root_cause_tier')) {
                $t->dropColumn(['root_cause_tier', 'root_cause_rule']);
            }
        });
    }
};
