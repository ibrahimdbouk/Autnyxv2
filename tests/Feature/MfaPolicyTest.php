<?php

namespace Tests\Feature;

use App\Support\MfaPolicy;
use Tests\TestCase;

/**
 * 3b — mandatory-MFA-for-super-admins policy. The panels wire this into
 * `->multiFactorAuthentication(..., isRequired: ...)`. The load-bearing property
 * is fail-open: MFA is required ONLY when the flag is on AND the acting user is a
 * positively-identified super admin — never for a regular user, and never when
 * the user can't be resolved (so a misfire can't lock the owner out).
 */
class MfaPolicyTest extends TestCase
{
    public function test_default_is_off_so_deploy_never_forces_mfa(): void
    {
        $this->assertFalse(MfaPolicy::requiredForSuperAdmins());
        $this->assertFalse(MfaPolicy::requiredForUser(null));
    }

    public function test_required_only_for_super_admins_when_flag_is_on(): void
    {
        config(['autnyx.require_mfa_super_admins' => true]);

        $tenant = $this->createTenant();
        $superAdmin = $this->createUser($tenant, superAdmin: true);
        $regular = $this->createUser($tenant);

        $this->assertTrue(MfaPolicy::requiredForUser($superAdmin), 'a super admin must be required to use MFA when the flag is on');
        $this->assertFalse(MfaPolicy::requiredForUser($regular), 'regular users are never forced');
    }

    public function test_fail_open_when_user_cannot_be_resolved(): void
    {
        config(['autnyx.require_mfa_super_admins' => true]);

        // No acting user → never require (never lock out break-glass on a misfire).
        $this->assertFalse(MfaPolicy::requiredForUser(null));
    }
}
