<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1 gap 1 — task reminders are sent once each: "due soon" and "overdue".
 * Nullable, additive; assortment_gaps is not a hot table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assortment_gaps', function (Blueprint $t) {
            $t->timestamp('reminded_at')->nullable();
            $t->timestamp('overdue_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assortment_gaps', function (Blueprint $t) {
            $t->dropColumn(['reminded_at', 'overdue_notified_at']);
        });
    }
};
