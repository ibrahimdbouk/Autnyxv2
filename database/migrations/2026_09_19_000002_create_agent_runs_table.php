<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * agent_runs — the audit spine for the AI agent layer.
 *
 * Every agent invocation (Campaign Action-Plan, Weekly Briefing, Data-Quality,
 * Action Follow-Up, Supplier-Negotiation Prep) writes one row here: the
 * deterministic INPUT snapshot it read, the structured OUTPUT it produced, the
 * model used, a confidence label, token usage, and — for the agents that act —
 * who accepted it and what was executed.
 *
 * This is what makes the boundary auditable: AI recommends/drafts here; a human
 * accepts; execution stays inside Autnyx (Actions, status changes, exports) and
 * is recorded against the same row. Nothing here writes to a customer ERP.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_runs')) {
            return;
        }

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            // Which agent produced this run, and what it is about.
            $table->string('agent_key')->index();          // e.g. campaign_action_plan
            $table->string('subject_type')->nullable();      // campaign | tenant | import | supplier | investigation
            $table->string('subject_id')->nullable();        // campaign name, tenant id, import id, supplier id…
            $table->string('title')->nullable();

            // Lifecycle: proposed → accepted → executed, or dismissed / failed;
            // informational agents (briefing, data-quality, supplier prep) land on 'complete'.
            $table->string('status')->default('proposed')->index();

            // The deterministic facts the agent read, and the structured result it wrote.
            $table->json('input')->nullable();
            $table->json('output')->nullable();

            // Provenance.
            $table->string('model')->nullable();
            $table->string('confidence')->nullable();
            $table->unsignedInteger('tokens_input')->nullable();
            $table->unsignedInteger('tokens_output')->nullable();
            $table->text('error')->nullable();

            // Who asked, who accepted, and when execution ran.
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('acted_by')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamp('executed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'agent_key', 'status']);
            $table->index(['tenant_id', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
