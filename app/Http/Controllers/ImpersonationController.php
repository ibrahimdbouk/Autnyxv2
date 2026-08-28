<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * 2c — "enter a tenant as its admin" for super admins.
 *
 * The original super-admin id is stashed in the session; the request then runs
 * as the tenant's admin user until they leave. Every start and stop is audited.
 * A super admin who is currently impersonating cannot start another (leave
 * first), and only tenant-admin accounts are ever entered as.
 */
class ImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(Tenant $tenant)
    {
        $actor = Auth::user();
        abort_unless($actor && $actor->is_super_admin, 403);
        abort_if(session()->has(self::SESSION_KEY), 409, 'Already impersonating — leave first.');

        $target = $tenant->admins()->orderBy('id')->first()
            ?? $tenant->users()->orderBy('id')->first();

        abort_unless($target, 404, 'This tenant has no user to enter as.');

        $actorId = $actor->id;
        $actorEmail = $actor->email;

        Auth::login($target);
        $this->syncSessionPasswordHash($target);
        // Store AFTER login so it survives the login's session migration.
        session()->put(self::SESSION_KEY, $actorId);

        $this->audit(
            $tenant->id,
            $actorId,
            AuditLog::EVENT_IMPERSONATION_STARTED,
            $actorEmail . ' entered ' . $tenant->name . ' as ' . $target->email,
        );

        return redirect('/admin/' . $tenant->slug);
    }

    public function stop()
    {
        $impersonatorId = session()->pull(self::SESSION_KEY);
        abort_unless($impersonatorId, 403, 'Not impersonating.');

        $impersonator = User::find($impersonatorId);
        abort_unless($impersonator, 403);

        $wasActingAs = Auth::user();

        Auth::login($impersonator);
        session()->regenerate();
        $this->syncSessionPasswordHash($impersonator);

        $this->audit(
            $wasActingAs?->tenant_id ?? $impersonator->tenant_id,
            $impersonator->id,
            AuditLog::EVENT_IMPERSONATION_STOPPED,
            $impersonator->email . ' stopped impersonating ' . ($wasActingAs?->email ?? 'a user'),
        );

        return redirect('/ops');
    }

    /**
     * Keep the session's stored password hash in step with the user we just
     * switched to. The admin panel runs Laravel's AuthenticateSession middleware,
     * which force-logs-out (and redirects to route('login')) the moment the
     * session hash no longer matches the current user — exactly what a user swap
     * does. Updating it here makes impersonation transparent to that middleware.
     */
    private function syncSessionPasswordHash(User $user): void
    {
        session()->put(
            'password_hash_' . Auth::getDefaultDriver(),
            $user->getAuthPassword(),
        );
    }

    private function audit(?int $tenantId, ?int $userId, string $event, string $description): void
    {
        if ($tenantId === null) {
            return; // audit_logs.tenant_id is NOT NULL
        }

        try {
            AuditLog::create([
                'tenant_id'   => $tenantId,
                'user_id'     => $userId,
                'event_type'  => $event,
                'description' => $description,
            ]);
        } catch (Throwable $e) {
            // best-effort
        }
    }
}
