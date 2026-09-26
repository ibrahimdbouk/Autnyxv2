<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W11 — the proof-of-value loop.
 *
 *   anomalies.feedback*          one-click "real / not real" from the team
 *                                (app, Action Center or the digest e-mail);
 *   investigation_outcomes
 *     .measured_capital          stock value released, as measured (kept apart
 *                                from recovered revenue — never added to it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anomalies', function (Blueprint $t) {
            $t->string('feedback', 12)->nullable();          // real | not_real
            $t->timestamp('feedback_at')->nullable();
            $t->unsignedBigInteger('feedback_by')->nullable();
            $t->string('feedback_via', 12)->nullable();      // app | email | action_center
        });

        Schema::table('investigation_outcomes', function (Blueprint $t) {
            $t->decimal('measured_capital', 18, 4)->nullable();
        });

    }

    public function down(): void
    {
        Schema::table('investigation_outcomes', fn (Blueprint $t) => $t->dropColumn('measured_capital'));
        Schema::table('anomalies', fn (Blueprint $t) => $t->dropColumn(['feedback', 'feedback_at', 'feedback_by', 'feedback_via']));
    }
};
