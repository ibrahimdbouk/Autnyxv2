<?php

namespace App\Services\Sso;

use App\Models\SsoConnection;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 1b — turn validated OIDC claims into a signed-in User, scoped to the tenant
 * that owns the connection.
 *
 * Rules:
 *   • email is taken from the configured claim; its domain must be permitted.
 *   • an existing user in the tenant is matched by email (case-insensitive).
 *   • otherwise JIT-provision one (if enabled) with a random password — SSO
 *     users authenticate through the IdP, never a local password.
 *   • an optional group claim maps membership to tenant-admin; it can grant
 *     admin but never silently revokes an existing admin.
 */
class SsoUserProvisioner
{
    /**
     * @param  array<string,mixed>  $claims
     */
    public function resolve(SsoConnection $conn, array $claims): User
    {
        $email = strtolower(trim((string) ($claims[$conn->email_claim] ?? '')));
        if ($email === '' || ! str_contains($email, '@')) {
            throw new RuntimeException('SSO response did not include a usable email claim.');
        }

        if (! $conn->permitsEmail($email)) {
            throw new RuntimeException('This email domain is not permitted for single sign-on.');
        }

        $shouldBeAdmin = $this->mapsToAdmin($conn, $claims);

        $user = User::query()
            ->where('tenant_id', $conn->tenant_id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($user !== null) {
            // Group claim may promote to admin; never auto-demote here.
            if ($shouldBeAdmin && ! $user->is_tenant_admin) {
                $user->is_tenant_admin = true;
                $user->save();
            }

            return $user;
        }

        if (! $conn->jit_provisioning) {
            throw new RuntimeException('No account exists for this user and automatic provisioning is disabled.');
        }

        $name = trim((string) ($claims[$conn->name_claim] ?? '')) ?: $email;

        return User::create([
            'tenant_id'       => $conn->tenant_id,
            'name'            => $name,
            'email'           => $email,
            'password'        => Hash::make(Str::random(48)),
            'is_tenant_admin' => $shouldBeAdmin,
            'is_super_admin'  => false,
            // visible_screens left null → unrestricted; an admin can narrow it later.
        ]);
    }

    /**
     * Does the group claim mark this user as a tenant admin?
     *
     * @param  array<string,mixed>  $claims
     */
    private function mapsToAdmin(SsoConnection $conn, array $claims): bool
    {
        if (empty($conn->admin_group_claim) || $conn->admin_group_value === null || $conn->admin_group_value === '') {
            return false;
        }

        $value = $claims[$conn->admin_group_claim] ?? null;
        $groups = is_array($value) ? $value : [$value];
        $groups = array_map(fn ($g) => (string) $g, $groups);

        return in_array((string) $conn->admin_group_value, $groups, true);
    }
}
