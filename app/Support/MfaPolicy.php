<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * 3b — the policy that decides whether MFA is *mandatory* for the acting user.
 *
 * Wired into both panels via `->multiFactorAuthentication([...], isRequired: ...)`.
 * Kept as a tiny, testable unit (rather than an inline closure in the providers)
 * so the fail-open guarantee is covered by a test, not just prose.
 *
 * Guarantee: enforcement is **fail-open**. MFA is required only when the flag is
 * on AND we can positively identify the acting user as a super admin. If the
 * user can't be resolved (null), we return false — never lock anyone out of
 * break-glass because of a misfire. Regular users are never forced.
 */
class MfaPolicy
{
    /** Is mandatory-MFA-for-super-admins switched on for this deployment? */
    public static function requiredForSuperAdmins(): bool
    {
        return (bool) config('autnyx.require_mfa_super_admins', false);
    }

    /**
     * Is MFA mandatory for the given user (defaults to the authenticated user)?
     * Fail-open: unknown user → false.
     */
    public static function requiredForUser(?Authenticatable $user = null): bool
    {
        if (! self::requiredForSuperAdmins()) {
            return false;
        }

        $user ??= auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }
}
