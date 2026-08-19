<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M23 — false-positive flag on anomalies.
 *
 * OutcomeService (M21) already writes is_false_positive when an outcome is
 * marked as a false positive, but the column never existed — the update was
 * silently swallowed by its try/catch. Adding it makes FP feedback real and
 * powers the Feature 9 false-positive-rate metric.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anomalies', function (Blueprint $table) {
            $table->boolean('is_false_positive')->default(false)->after('dismissed_by');
            $table->index(['tenant_id', 'is_false_positive']);
        });
    }

    public function down(): void
    {
        Schema::table('anomalies', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_false_positive']);
            $table->dropColumn('is_false_positive');
        });
    }
};
