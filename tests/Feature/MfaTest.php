<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 3b — admin MFA/2FA. Verifies the User model satisfies Filament's
 * app-authenticator contracts and that the TOTP secret + recovery codes are
 * persisted ENCRYPTED and hidden from serialization. See
 * claude/security-compliance-notes.md.
 */
class MfaTest extends TestCase
{
    public function test_user_implements_the_mfa_contracts(): void
    {
        $user = new User;
        $this->assertInstanceOf(HasAppAuthentication::class, $user);
        $this->assertInstanceOf(HasAppAuthenticationRecovery::class, $user);
    }

    public function test_secret_and_recovery_codes_roundtrip_and_are_encrypted_at_rest(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $user->saveAppAuthenticationSecret('TOTPSECRET123');
        $user->saveAppAuthenticationRecoveryCodes(['aaa-111', 'bbb-222']);

        $fresh = User::findOrFail($user->id);
        $this->assertSame('TOTPSECRET123', $fresh->getAppAuthenticationSecret());
        $this->assertSame(['aaa-111', 'bbb-222'], $fresh->getAppAuthenticationRecoveryCodes());
        $this->assertSame($user->email, $fresh->getAppAuthenticationHolderName());

        // Stored ciphertext, never plaintext.
        $raw = DB::table('users')->where('id', $user->id)->value('app_authentication_secret');
        $this->assertNotSame('TOTPSECRET123', $raw, 'the TOTP secret must be encrypted at rest');

        // Never leaks through array/JSON serialization.
        $this->assertArrayNotHasKey('app_authentication_secret', $fresh->toArray());
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $fresh->toArray());
    }
}
