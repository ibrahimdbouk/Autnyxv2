<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M23 / Feature 6 — Snooze
 *
 * Snooze = temporary silence on a single investigation. It does NOT delete
 * history and does NOT feed false-positive learning. Snoozes return
 * automatically once snoozed_until passes (cleared by the noise:expire job).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investigations', function (Blueprint $table) {
            $table->timestamp('snoozed_until')->nullable()->after('closed_at');
            $table->string('snooze_reason')->nullable()->after('snoozed_until');
            $table->text('snooze_notes')->nullable()->after('snooze_reason');
            $table->unsignedBigInteger('snoozed_by')->nullable()->after('snooze_notes');
            $table->foreign('snoozed_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('snoozed_at')->nullable()->after('snoozed_by');

            $table->index(['tenant_id', 'snoozed_until']);
        });
    }

    public function down(): void
    {
        Schema::table('investigations', function (Blueprint $table) {
            $table->dropForeign(['snoozed_by']);
            $table->dropColumn([
                'snoozed_until', 'snooze_reason', 'snooze_notes', 'snoozed_by', 'snoozed_at',
            ]);
        });
    }
};
