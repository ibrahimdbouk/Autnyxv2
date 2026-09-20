<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * The AI data-quality page moved to its own screen key `data_quality` (distinct
 * from the deterministic Data Health Center's `data_health`). Chosen policy is
 * ON-FOR-EVERYONE: users with a null visible_screens already see it; this appends
 * the new key to any user who has an explicit (restricted) screen list so they
 * see it too. Idempotent; uses saveQuietly to avoid mass audit writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        User::whereNotNull('visible_screens')->cursor()->each(function (User $user) {
            $current = is_array($user->visible_screens) ? $user->visible_screens : [];
            if (in_array('data_quality', $current, true)) {
                return;
            }
            $user->visible_screens = array_values(array_unique(array_merge($current, ['data_quality'])));
            $user->saveQuietly();
        });
    }

    public function down(): void
    {
        User::whereNotNull('visible_screens')->cursor()->each(function (User $user) {
            $current = is_array($user->visible_screens) ? $user->visible_screens : [];
            $stripped = array_values(array_diff($current, ['data_quality']));
            if ($stripped !== $current) {
                $user->visible_screens = $stripped;
                $user->saveQuietly();
            }
        });
    }
};
