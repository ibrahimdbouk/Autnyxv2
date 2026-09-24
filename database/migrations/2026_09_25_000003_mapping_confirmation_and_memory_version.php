<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP3.3 (audit H24):
 *  • imports.mapping_confirmed_at / _by — a PERSON confirmed the mapping. Only
 *    such imports may teach the mapping memory.
 *  • mapping_memories.schema_version — memories from an older schema/alias set
 *    are ignored. Existing rows get 0, i.e. every memory learned before this
 *    change (including ones learned from auto-accepted SFTP/API mappings) is
 *    retired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $t) {
            if (! Schema::hasColumn('imports', 'mapping_confirmed_at')) {
                $t->timestamp('mapping_confirmed_at')->nullable();
            }
            if (! Schema::hasColumn('imports', 'mapping_confirmed_by')) {
                $t->foreignId('mapping_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('mapping_memories', function (Blueprint $t) {
            if (! Schema::hasColumn('mapping_memories', 'schema_version')) {
                $t->unsignedSmallInteger('schema_version')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $t) {
            $t->dropConstrainedForeignId('mapping_confirmed_by');
            $t->dropColumn('mapping_confirmed_at');
        });
        Schema::table('mapping_memories', fn (Blueprint $t) => $t->dropColumn('schema_version'));
    }
};
