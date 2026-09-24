<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product physical dimensions — length / width / height / volume. Nullable and
 * additive (hasColumn-guarded), like the wider hardening pass. Useful for
 * planogram/space, palletisation, shipping and cube-utilisation analytics as
 * those feeds come online.
 */
return new class extends Migration
{
    private const COLS = ['length_mm', 'width_mm', 'height_mm', 'volume_cm3'];

    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }
        Schema::table('products', function (Blueprint $t) {
            if (! Schema::hasColumn('products', 'length_mm'))  { $t->decimal('length_mm', 12, 2)->nullable(); }
            if (! Schema::hasColumn('products', 'width_mm'))   { $t->decimal('width_mm', 12, 2)->nullable(); }
            if (! Schema::hasColumn('products', 'height_mm'))  { $t->decimal('height_mm', 12, 2)->nullable(); }
            if (! Schema::hasColumn('products', 'volume_cm3')) { $t->decimal('volume_cm3', 14, 2)->nullable(); }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }
        $present = array_values(array_filter(self::COLS, fn ($c) => Schema::hasColumn('products', $c)));
        if ($present !== []) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn($present));
        }
    }
};
