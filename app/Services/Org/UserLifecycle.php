<?php

namespace App\Services\Org;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Platform core — leavers. Deactivating a person (instead of deleting them)
 * keeps everything they did attributable while cutting access at once: no
 * sign-in (User::canAccessPanel), sessions ended, push devices revoked, no
 * e-mail (DropDeactivatedRecipients), and signed links (store sheet, one-click
 * feedback) refused. Their store and region links are kept, so reactivating
 * restores them as they were.
 */
class UserLifecycle
{
    public function __construct(private DeviceRegistry $devices) {}

    public function deactivate(User $user, ?User $actor = null): void
    {
        $this->guard($user, $actor);
        if ($user->deactivated_at !== null) {
            return;
        }

        DB::transaction(function () use ($user) {
            $user->forceFill(['deactivated_at' => now()])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $this->devices->revokeAllFor($user, 'deactivated');
        });
    }

    public function reactivate(User $user, ?User $actor = null): void
    {
        $this->guard($user, $actor);
        if ($user->deactivated_at === null) {
            return;
        }
        $user->forceFill(['deactivated_at' => null])->save();
    }

    private function guard(User $user, ?User $actor): void
    {
        if ($user->isOwner() || $user->is_super_admin) {
            throw new \InvalidArgumentException('Platform administrators cannot be deactivated here.');
        }
        if ($actor !== null) {
            if ((int) $actor->id === (int) $user->id) {
                throw new \InvalidArgumentException('You cannot deactivate yourself.');
            }
            if (! $actor->is_super_admin && (! $actor->canManageUsers() || (int) $actor->tenant_id !== (int) $user->tenant_id)) {
                throw new \InvalidArgumentException('Not allowed.');
            }
        }
    }
}
