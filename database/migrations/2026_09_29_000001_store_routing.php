<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W12 — store routing. A user can be linked to the store(s) they run; each
 * then gets a daily digest of their own stores only (findings to confirm,
 * stock to count), by e-mail and — when Teams is connected — a Teams ping,
 * with a signed store sheet that needs no login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->timestamps();

            $t->unique(['user_id', 'store_id']);
            $t->index(['tenant_id', 'store_id']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->boolean('store_digest')->default(true);          // the daily store digest (on for anyone linked to a store)
            $t->timestamp('store_digest_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['store_digest', 'store_digest_sent_at']));
        Schema::dropIfExists('store_user');
    }
};
