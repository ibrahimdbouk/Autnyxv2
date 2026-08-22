<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks HOW a comment was created — 'web' (in-app) or 'email' (a user replying
 * to a notification email) — plus the external message id for de-duplication of
 * inbound emails. Surfaced in the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investigation_comments', function (Blueprint $table) {
            $table->string('source')->default('web')->after('body'); // web | email
            $table->string('external_ref')->nullable()->after('source'); // inbound email message-id
            $table->index(['investigation_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('investigation_comments', function (Blueprint $table) {
            $table->dropIndex(['investigation_id', 'external_ref']);
            $table->dropColumn(['source', 'external_ref']);
        });
    }
};
