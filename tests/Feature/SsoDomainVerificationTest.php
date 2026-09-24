<?php

namespace Tests\Feature;

use App\Filament\Resources\SsoConnectionResource;
use App\Models\SsoConnection;
use App\Models\User;
use App\Services\Sso\DnsTxtResolver;
use App\Services\Sso\SsoDomainVerifier;
use Tests\TestCase;

/**
 * WP2.1 — domain ownership proof (audit M8), endpoint overrides are
 * super-admin only (audit C5), and ownership is an immutable flag (audit M1).
 */
class SsoDomainVerificationTest extends TestCase
{
    private function fakeDns(array $records): void
    {
        $this->app->instance(DnsTxtResolver::class, new class($records) extends DnsTxtResolver {
            public function __construct(private array $records) {}
            public function txt(string $domain): array { return $this->records[$domain] ?? []; }
        });
    }

    private function connection(int $tenantId, array $domains): SsoConnection
    {
        return SsoConnection::create([
            'tenant_id' => $tenantId, 'enabled' => true, 'issuer' => 'https://idp.test',
            'client_id' => 'c', 'client_secret' => 's', 'allowed_domains' => $domains,
        ]);
    }

    public function test_only_domains_with_the_txt_record_are_verified(): void
    {
        $conn = $this->connection($this->createTenant()->id, ['acme.com', 'other.com']);
        $txt = app(SsoDomainVerifier::class)->expectedTxt($conn);
        $this->fakeDns(['acme.com' => ['v=spf1 -all', '"' . $txt . '"']]);

        $result = app(SsoDomainVerifier::class)->verify($conn);

        $this->assertSame(['acme.com'], $result['verified']);
        $this->assertArrayHasKey('other.com', $result['failed']);
        $this->assertTrue($conn->fresh()->permitsEmail('a@acme.com'));
        $this->assertFalse($conn->fresh()->permitsEmail('a@other.com'));
    }

    public function test_a_domain_can_be_verified_by_one_organisation_only(): void
    {
        $first = $this->connection($this->createTenant()->id, ['acme.com']);
        $first->forceFill(['verified_domains' => ['acme.com']])->save();

        $attacker = $this->connection($this->createTenant()->id, ['acme.com']);
        $this->fakeDns(['acme.com' => [app(SsoDomainVerifier::class)->expectedTxt($attacker)]]);

        $result = app(SsoDomainVerifier::class)->verify($attacker);

        $this->assertSame([], $result['verified']);
        $this->assertFalse($attacker->fresh()->permitsEmail('ceo@acme.com'));
    }

    public function test_verified_domains_cannot_be_mass_assigned_from_a_form(): void
    {
        $conn = $this->connection($this->createTenant()->id, ['acme.com']);
        $conn->update(['verified_domains' => ['acme.com'], 'domain_verification_token' => 'x']);

        $this->assertSame([], $conn->fresh()->verifiedDomains());
    }

    public function test_no_verified_domains_means_nobody_can_sign_in(): void
    {
        $conn = $this->connection($this->createTenant()->id, []);
        $this->assertFalse($conn->permitsEmail('anyone@anything.com'));
    }

    public function test_removing_an_allowed_domain_unverifies_it(): void
    {
        $conn = $this->connection($this->createTenant()->id, ['acme.com', 'b.com']);
        $conn->forceFill(['verified_domains' => ['acme.com', 'b.com']])->save();

        $conn->update(['allowed_domains' => ['acme.com']]);

        $this->assertSame(['acme.com'], $conn->fresh()->verifiedDomains());
    }

    public function test_endpoint_overrides_are_hidden_from_tenant_admins(): void
    {
        $tenant = $this->createTenant();
        $conn = $this->connection($tenant->id, ['acme.com']);
        $this->actingAsTenantAdmin($tenant);

        $this->get(SsoConnectionResource::getUrl('edit', ['record' => $conn, 'tenant' => $tenant]))
            ->assertOk()
            ->assertSee('Domain verification')
            ->assertDontSee('Advanced — endpoint overrides');
    }

    public function test_ownership_is_a_flag_not_an_email(): void
    {
        $tenant = $this->createTenant();
        $impostor = User::factory()->create(['tenant_id' => $tenant->id, 'email' => config('autnyx.owner_email')]);
        $this->assertFalse($impostor->isOwner(), 'holding the owner email does not make you owner');

        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $owner->update(['email' => 'new-address@example.com']);
        $this->assertTrue($owner->fresh()->isOwner(), 'the owner changing email stays owner');

        $admin = $this->createUser($tenant, admin: true);
        $this->actingAs($admin);
        $victim = $this->createUser($tenant);
        $victim->forceFill(['is_owner' => true])->save();
        $this->assertFalse($victim->fresh()->isOwner(), 'in-app changes to is_owner are reverted');
    }
}
