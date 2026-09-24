<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP3.6 (audit H28, D6):
 *  • imports.process_phase — a firewall import is SCREENED first (quality
 *    decided, nothing written) and only then WRITTEN; a RED batch is held
 *    (status "held") until an admin promotes it.
 *  • import_quality.overridden_by / overridden_at — who promoted a RED batch.
 *  • import_quality.state widened (varchar 8 → 16) for the new "duplicate"
 *    state; widening a varchar is a catalogue-only change in PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $t) {
            if (! Schema::hasColumn('imports', 'process_phase')) {
                $t->string('process_phase', 10)->nullable();
            }
        });
        Schema::table('import_quality', function (Blueprint $t) {
            $t->string('state', 16)->default('green')->change();
            if (! Schema::hasColumn('import_quality', 'overridden_by')) {
                $t->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('import_quality', 'overridden_at')) {
                $t->timestamp('overridden_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('imports', fn (Blueprint $t) => $t->dropColumn('process_phase'));
        Schema::table('import_quality', function (Blueprint $t) {
            $t->dropConstrainedForeignId('overridden_by');
            $t->dropColumn('overridden_at');
        });
    }
};
