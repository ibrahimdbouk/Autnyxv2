<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * RBAC roll-out for the AI agent pages (Action Queue, Weekly Briefing,
 * Follow-Ups, Supplier Prep, Data Health) — chosen policy: ON FOR EVERYONE.
 *
 * canSeeScreen() treats a NULL visible_screens as unrestricted, so users with no
 * explicit list (and all new users) already see every gate-able screen. Only
 * users an admin has explicitly RESTRICTED to a screen list would otherwise miss
 * the new pages — so this appends the five agent screen keys to those users'
 * lists. Idempotent: re-running merges without duplicating, and users already
 * covered are skipped. Uses saveQuietly() to avoid mass audit-log writes and the
 * per-save role hooks.
 */
return new class extends Migration
{
    private const AGENT_SCREENS = [
        'action_queue',
        'weekly_briefing',
        'follow_ups',
        'supplier_prep',
        'data_health',
    ];

    public function up(): void
    {
        User::whereNotNull('visible_screens')->cursor()->each(function (User $user) {
            $current = is_array($user->visible_screens) ? $user->visible_screens : [];

            // Empty array is still an explicit restriction ("sees nothing") — but
            // the chosen policy is on-for-everyone, so grant the agent screens too.
            $merged = array_values(array_unique(array_merge($current, self::AGENT_SCREENS)));

            if ($merged !== $current) {
                $user->visible_screens = $merged;
                $user->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        // Reverse: strip the agent screen keys from any explicit list.
        User::whereNotNull('visible_screens')->cursor()->each(function (User $user) {
            $current = is_array($user->visible_screens) ? $user->visible_screens : [];
            $stripped = array_values(array_diff($current, self::AGENT_SCREENS));

            if ($stripped !== $current) {
                $user->visible_screens = $stripped;
                $user->saveQuietly();
            }
        });
    }
};
