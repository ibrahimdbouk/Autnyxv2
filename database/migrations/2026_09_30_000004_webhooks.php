<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W13 — outbound webhooks: tell another system that something happened
 * (an investigation opened or resolved, a recovery measured, a count
 * recorded, a finding opened). Notification only — nothing is executed.
 * Every delivery is signed (HMAC-SHA256), logged and retried with backoff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('url', 500);
            $t->text('secret');                               // encrypted
            $t->json('events');
            $t->string('min_severity', 10)->default('high');  // finding.opened: high | medium | low
            $t->boolean('active')->default(true);
            $t->unsignedBigInteger('last_finding_id')->nullable();   // finding.opened cursor
            $t->unsignedInteger('failure_streak')->default(0);
            $t->timestamp('last_success_at')->nullable();
            $t->timestamp('last_failure_at')->nullable();
            $t->string('disabled_reason', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['tenant_id', 'active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $t->uuid('event_id');
            $t->string('event', 40);
            $t->jsonb('payload');
            $t->string('status', 12)->default('pending');   // pending | delivered | failed
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->unsignedSmallInteger('response_status')->nullable();
            $t->string('response_excerpt', 500)->nullable();
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();

            $t->index(['webhook_endpoint_id', 'id']);
            $t->index(['status', 'next_attempt_at']);
            $t->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
