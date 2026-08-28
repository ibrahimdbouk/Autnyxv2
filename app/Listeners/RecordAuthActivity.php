<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Throwable;

/**
 * Auth observability for the /ops console: stamp `last_login_at` on a successful
 * login (via a query update, so it never trips the model's audit / owner hooks)
 * and audit failed password logins for the security view.
 */
class RecordAuthActivity
{
    public function login(Login $event): void
    {
        try {
            $user = $event->user;
            if ($user instanceof User) {
                User::whereKey($user->getKey())->update(['last_login_at' => now()]);
            }
        } catch (Throwable $e) {
            // best-effort
        }
    }

    public function failed(Failed $event): void
    {
        try {
            $email = $event->credentials['email'] ?? null;
            if (! $email) {
                return;
            }

            $user = User::where('email', $email)->first();
            // audit_logs.tenant_id is NOT NULL — only log for a known tenant user.
            if (! $user || ! $user->tenant_id) {
                return;
            }

            AuditLog::create([
                'tenant_id'   => $user->tenant_id,
                'user_id'     => $user->id,
                'event_type'  => AuditLog::EVENT_LOGIN_FAILED,
                'description' => 'Failed password login for ' . $email,
            ]);
        } catch (Throwable $e) {
            // best-effort
        }
    }
}
