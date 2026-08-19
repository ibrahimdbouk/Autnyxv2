<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table) {
            $table->string('priority')->default('medium')->after('status');
            $table->string('escalation_state')->nullable()->after('priority');
            $table->text('completion_notes')->nullable()->after('notes');
            $table->timestamp('acknowledged_at')->nullable()->after('completed_at');
        });

        // Migrate existing 'pending' statuses to 'unassigned' where no assigned_to
        DB::statement("
            UPDATE actions
            SET status = 'unassigned'
            WHERE status = 'pending'
              AND assigned_to IS NULL
              AND assigned_team_id IS NULL
        ");

        // Remaining 'pending' with assignment become 'assigned'
        DB::statement("
            UPDATE actions
            SET status = 'assigned'
            WHERE status = 'pending'
        ");
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table) {
            $table->dropColumn(['priority', 'escalation_state', 'completion_notes', 'acknowledged_at']);
        });
    }
};
