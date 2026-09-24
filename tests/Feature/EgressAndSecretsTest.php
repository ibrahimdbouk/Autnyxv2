<?php

namespace Tests\Feature;

use App\Filament\Resources\ApiConnectionResource;
use App\Models\ApiConnection;
use App\Models\ApiFeed;
use App\Models\OutboundTarget;
use App\Models\SftpConnection;
use App\Models\TeamsConnection;
use App\Platform\Integration\ActionIntent;
use App\Platform\Integration\Connectors\WebhookConnector;
use App\Services\Integrations\GenericRestConnector;
use App\Services\Ops\TenantOffboardingService;
use App\Services\Sftp\SftpHostKeyProbe;
use App\Services\Sftp\SftpService;
use App\Services\Teams\GraphClient;
use App\Support\Http\EgressGuard;
use App\Support\Http\HostResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\FakeIdp;
use Tests\TestCase;

/**
 * WP2.2 — SSRF egress guard (audit H12/M4), secrets never leave the server
 * (M5, M6, L1), Teams bound to a PROVEN Microsoft tenant (M7), SFTP host-key pin.
 */
class EgressAndSecretsTest extends TestCase
{
    use FakeIdp;

    private function resolverReturning(array $map): void
    {
        config(['autnyx.egress.resolve_dns' => true]);
        $this->app->instance(HostResolver::class, new class($map) extends HostResolver {
            public function __construct(private array $map) {}
            public function resolve(string $host): array { return $this->map[$host] ?? []; }
        });
    }

    private function blocked(string $url, ?string $sameHostAs = null): bool
    {
        try {
            app(EgressGuard::class)->check($url, $sameHostAs);

            return false;
        } catch (RuntimeException) {
            return true;
        }
    }

    // ── Egress guard ─────────────────────────────────────────────────────────

    public function test_internal_and_metadata_targets_are_blocked(): void
    {
        $this->resolverReturning([
            'erp.customer.com' => ['52.10.20.30'],
            'rebind.evil.com'  => ['52.10.20.31', '169.254.169.254'],
            'intra.evil.com'   => ['10.0.0.5'],
            'v6.evil.com'      => ['fd00::1'],
        ]);

        foreach ([
            'http://erp.customer.com/api',              // plain http
            'https://169.254.169.254/latest/meta-data', // cloud metadata
            'https://127.0.0.1/', 'https://[::1]/', 'https://10.1.2.3/', 'https://192.168.1.1/', 'https://100.64.0.1/',
            'https://localhost/', 'https://db.internal/', 'https://printer.local/',
            'https://rebind.evil.com/', 'https://intra.evil.com/', 'https://v6.evil.com/',
            'https://unresolvable.example/', 'https://user:pw@erp.customer.com/',
            'file:///etc/passwd', 'gopher://erp.customer.com/',
        ] as $url) {
            $this->assertTrue($this->blocked($url), "{$url} should be blocked");
        }

        $this->assertFalse($this->blocked('https://erp.customer.com/api'));
        $opts = app(EgressGuard::class)->check('https://erp.customer.com:8443/api');
        $this->assertSame(['erp.customer.com:8443:52.10.20.30'], $opts['curl'][CURLOPT_RESOLVE], 'the connection is pinned to the checked address');
    }

    public function test_continuation_links_must_stay_on_the_same_host(): void
    {
        $this->assertTrue($this->blocked('https://evil.com/next', 'https://erp.customer.com/api'));
        $this->assertFalse($this->blocked('https://erp.customer.com/api?page=2', 'https://erp.customer.com/api'));
    }

    public function test_connector_refuses_an_internal_base_url_and_a_cross_host_next_link(): void
    {
        $conn = new ApiConnection(['base_url' => 'https://169.254.169.254', 'auth_type' => 'none']);
        $feed = new ApiFeed(['endpoint' => '/latest', 'page_strategy' => 'none']);
        try {
            iterator_to_array((new GenericRestConnector())->fetch($conn, $feed), false);
            $this->fail('metadata endpoint was fetched');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Blocked', $e->getMessage());
        }

        Http::fake(['api.test/*' => Http::response(['value' => [['id' => 1]], '@odata.nextLink' => 'https://attacker.test/steal'], 200)]);
        $conn = new ApiConnection(['base_url' => 'https://api.test', 'auth_type' => 'none']);
        $feed = new ApiFeed(['endpoint' => '/orders', 'records_path' => 'value', 'page_strategy' => 'next_link']);
        $this->expectException(RuntimeException::class);
        iterator_to_array((new GenericRestConnector())->fetch($conn, $feed), false);
    }

    public function test_outbound_webhook_to_internal_address_is_not_sent(): void
    {
        Http::fake();
        $t = $this->createTenant();
        $target = OutboundTarget::create(['tenant_id' => $t->id, 'kind' => OutboundTarget::KIND_WEBHOOK, 'name' => 'x', 'endpoint' => 'https://10.0.0.8/hook', 'active' => true]);

        $result = (new WebhookConnector())->dispatch(new ActionIntent(...self::intentArgs($t->id)), $target);

        $this->assertNotSame('acknowledged', $result->status ?? null);
        Http::assertNothingSent();
    }

    private static function intentArgs(int $tenantId): array
    {
        $ctor = (new \ReflectionClass(ActionIntent::class))->getConstructor();
        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $type = (string) $p->getType();
            $args[$p->getName()] = match (true) {
                $p->isDefaultValueAvailable() => $p->getDefaultValue(),
                str_contains($type, 'int')    => $tenantId,
                str_contains($type, 'float')  => 0.0,
                str_contains($type, 'array')  => [],
                default                       => 'x',
            };
        }

        return $args;
    }

    // ── Secrets ──────────────────────────────────────────────────────────────

    public function test_api_connection_secrets_never_reach_the_browser_and_blank_keeps_them(): void
    {
        $t = $this->createTenant();
        $this->actingAsTenantAdmin($t);
        $conn = ApiConnection::create([
            'tenant_id' => $t->id, 'name' => 'ERP', 'provider' => 'generic_rest', 'base_url' => 'https://erp.test',
            'auth_type' => ApiConnection::AUTH_OAUTH2_CC, 'is_active' => true, 'status' => ApiConnection::STATUS_NEVER,
            'auth_config' => ['token_url' => 'https://login.test/token', 'client_id' => 'cid', 'client_secret' => 'TOP-SECRET-VALUE'],
        ]);

        $this->assertArrayNotHasKey('auth_config', $conn->toArray());

        $html = $this->get(ApiConnectionResource::getUrl('edit', ['record' => $conn, 'tenant' => $t]))->assertOk()->getContent();
        $this->assertStringNotContainsString('TOP-SECRET-VALUE', $html);
        $this->assertStringContainsString('cid', $html, 'non-secret settings are still shown');

        $page = new \App\Filament\Resources\ApiConnectionResource\Pages\EditApiConnection();
        $page->record = $conn;
        $save = (new \ReflectionMethod($page, 'mutateFormDataBeforeSave'));
        $data = $save->invoke($page, ['auth_config' => ['token_url' => 'https://login.test/token', 'client_id' => 'cid2', 'client_secret' => '']]);
        $this->assertSame('TOP-SECRET-VALUE', $data['auth_config']['client_secret'], 'a blank secret keeps the stored one');
        $this->assertSame('cid2', $data['auth_config']['client_id']);
    }

    public function test_outbound_credentials_are_encrypted_at_rest_and_legacy_rows_are_repaired(): void
    {
        $t = $this->createTenant();
        $target = OutboundTarget::create(['tenant_id' => $t->id, 'kind' => OutboundTarget::KIND_WEBHOOK, 'name' => 'x',
            'endpoint' => 'https://erp.test/hook', 'active' => true, 'config' => ['secret' => 'HMAC-SECRET', 'token' => 'TKN']]);

        $raw = DB::table('outbound_targets')->where('id', $target->id)->value('config');
        $this->assertStringNotContainsString('HMAC-SECRET', $raw);
        $this->assertSame('HMAC-SECRET', $target->fresh()->config['secret']);
        $this->assertArrayNotHasKey('config', $target->toArray());

        // A row written before encryption (plaintext JSON) still reads, and the command encrypts it.
        DB::table('outbound_targets')->where('id', $target->id)->update(['config' => json_encode(['secret' => 'LEGACY'])]);
        $this->assertSame('LEGACY', $target->fresh()->config['secret']);
        $this->artisan('outbound:encrypt-secrets')->assertSuccessful();
        $this->assertStringNotContainsString('LEGACY', DB::table('outbound_targets')->where('id', $target->id)->value('config'));
        $this->assertSame('LEGACY', $target->fresh()->config['secret']);
    }

    public function test_offboarding_export_contains_no_credentials(): void
    {
        $t = $this->createTenant(['slug' => 'leaving-co']);
        $u = $this->createUser($t);
        ApiConnection::create(['tenant_id' => $t->id, 'name' => 'ERP', 'provider' => 'generic_rest', 'base_url' => 'https://erp.test',
            'auth_type' => ApiConnection::AUTH_BEARER, 'is_active' => true, 'status' => ApiConnection::STATUS_NEVER, 'auth_config' => ['token' => 'BEARER-XYZ']]);

        $zipPath = app(TenantOffboardingService::class)->export($t);
        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $all = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $all .= $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($zipPath);

        $this->assertStringContainsString($u->email, $all, 'the data itself is exported');
        $this->assertStringNotContainsString($u->password, $all);
        $this->assertStringNotContainsString('auth_config', $all);
    }

    // ── Teams ────────────────────────────────────────────────────────────────

    public function test_webhook_host_allow_list(): void
    {
        $this->assertTrue(TeamsConnection::webhookHostAllowed('https://acme.webhook.office.com/webhookb2/abc'));
        $this->assertTrue(TeamsConnection::webhookHostAllowed('https://prod-12.westeurope.logic.azure.com/workflows/x'));
        $this->assertFalse(TeamsConnection::webhookHostAllowed('https://evil.com/webhook.office.com'));
        $this->assertFalse(TeamsConnection::webhookHostAllowed('https://webhook.office.com.evil.com/x'));
        $this->assertFalse(TeamsConnection::webhookHostAllowed('http://acme.webhook.office.com/x'));
        $this->assertFalse(TeamsConnection::webhookHostAllowed('https://169.254.169.254/'));
    }

    public function test_graph_refuses_an_unverified_microsoft_tenant(): void
    {
        Http::fake();
        $t = $this->createTenant();
        $conn = TeamsConnection::create(['tenant_id' => $t->id, 'is_active' => true]);
        $conn->forceFill(['aad_tenant_id' => 'someone-elses-tenant'])->save(); // typed in, never proven

        $this->expectException(RuntimeException::class);
        try {
            app(GraphClient::class)->resolveUserIdByEmail($conn, 'ceo@victim.com');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_aad_tenant_id_cannot_be_typed_in(): void
    {
        $t = $this->createTenant();
        $conn = TeamsConnection::create(['tenant_id' => $t->id, 'is_active' => true, 'aad_tenant_id' => 'typed-guid']);

        $this->assertNull($conn->fresh()->aad_tenant_id);
    }

    public function test_admin_consent_callback_binds_the_signed_tid(): void
    {
        config(['services.teams.client_id' => 'autnyx-app', 'services.teams.client_secret' => 's']);
        $t = $this->createTenant(['slug' => 'acme']);
        $admin = $this->actingAsTenantAdmin($t);
        $conn = TeamsConnection::create(['tenant_id' => $t->id, 'is_active' => true]);
        $tid = '11111111-2222-3333-4444-555555555555';

        $this->get(route('teams.consent.connect', ['tenant' => 'acme']))->assertRedirect();
        $flow = session('teams_consent');

        $idToken = $this->signedIdToken([
            'iss' => "https://login.microsoftonline.com/{$tid}/v2.0", 'aud' => 'autnyx-app', 'tid' => $tid,
            'exp' => time() + 600, 'nonce' => $flow['nonce'],
        ]);
        Http::fake([
            'login.microsoftonline.com/organizations/oauth2/v2.0/token' => Http::response(['id_token' => $idToken]),
            'login.microsoftonline.com/common/discovery/v2.0/keys'     => Http::response($this->idpJwks()),
        ]);

        $this->get(route('teams.consent.callback', ['state' => $flow['state'], 'code' => 'c']))->assertRedirect();

        $conn->refresh();
        $this->assertSame($tid, $conn->aad_tenant_id);
        $this->assertTrue($conn->isMicrosoftTenantVerified());
    }

    public function test_admin_consent_callback_rejects_a_forged_token(): void
    {
        config(['services.teams.client_id' => 'autnyx-app', 'services.teams.client_secret' => 's']);
        $t = $this->createTenant(['slug' => 'acme']);
        $this->actingAsTenantAdmin($t);
        $conn = TeamsConnection::create(['tenant_id' => $t->id, 'is_active' => true]);

        $this->get(route('teams.consent.connect', ['tenant' => 'acme']));
        $flow = session('teams_consent');
        $rogue = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $tid = '99999999-2222-3333-4444-555555555555';
        $forged = $this->signedIdToken(['iss' => "https://login.microsoftonline.com/{$tid}/v2.0", 'aud' => 'autnyx-app', 'tid' => $tid, 'exp' => time() + 600, 'nonce' => $flow['nonce']], 'k1', $rogue);
        Http::fake([
            'login.microsoftonline.com/organizations/oauth2/v2.0/token' => Http::response(['id_token' => $forged]),
            'login.microsoftonline.com/common/discovery/v2.0/keys'     => Http::response($this->idpJwks()),
        ]);

        $this->get(route('teams.consent.callback', ['state' => $flow['state'], 'code' => 'c']));

        $this->assertNull($conn->fresh()->aad_tenant_id);
    }

    public function test_consent_requires_an_admin_of_that_organisation(): void
    {
        $t = $this->createTenant(['slug' => 'acme']);
        TeamsConnection::create(['tenant_id' => $t->id, 'is_active' => true]);

        $this->actingAsAnalyst($t);
        $this->get(route('teams.consent.connect', ['tenant' => 'acme']))->assertForbidden();

        $this->actingAsTenantAdmin($this->createTenant());
        $this->get(route('teams.consent.connect', ['tenant' => 'acme']))->assertForbidden();
    }

    // ── SFTP ─────────────────────────────────────────────────────────────────

    public function test_sftp_host_key_is_pinned_on_first_use_and_internal_hosts_refused(): void
    {
        $this->app->instance(SftpHostKeyProbe::class, new class extends SftpHostKeyProbe {
            public function fingerprint(string $host, int $port): ?string { return 'sha256:FAKEFINGERPRINT'; }
        });
        $t = $this->createTenant();
        $conn = SftpConnection::create(['tenant_id' => $t->id, 'name' => 's', 'host' => 'sftp.example.com', 'port' => 22,
            'username' => 'u', 'auth_type' => SftpConnection::AUTH_PASSWORD, 'password' => 'p', 'is_active' => true, 'status' => SftpConnection::STATUS_NEVER]);

        app(SftpService::class)->disk($conn);
        $this->assertSame('sha256:FAKEFINGERPRINT', $conn->fresh()->host_key_fingerprint);
        $this->assertSame('sha256:FAKEFINGERPRINT', $conn->fresh()->diskConfig()['hostFingerprint']);

        $conn->update(['host' => 'sftp2.example.com']);
        $this->assertNull($conn->fresh()->host_key_fingerprint, 'a new server is re-trusted on next connect');

        $conn->update(['host' => '10.0.0.4']);
        $this->expectException(RuntimeException::class);
        app(SftpService::class)->disk($conn->fresh());
    }
}
