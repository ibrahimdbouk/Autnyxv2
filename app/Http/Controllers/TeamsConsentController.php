<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TeamsConnection;
use App\Services\Sso\IdTokenVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * WP2.2 (audit H12 / M7) — bind a Teams connection to the customer's Microsoft
 * 365 tenant by PROOF, not by a typed-in GUID.
 *
 * Autnyx's single multi-tenant Entra app can get app-only Graph tokens for any
 * Microsoft tenant that consented to it, so a typed-in tenant id let one Autnyx
 * customer act inside another customer's directory. Here a Microsoft 365 admin
 * signs in and grants admin consent; Microsoft returns a SIGNED ID token whose
 * `tid` claim is the tenant that admin belongs to — that is what we store.
 */
class TeamsConsentController extends Controller
{
    private const AUTHORITY = 'https://login.microsoftonline.com/organizations/oauth2/v2.0';
    private const JWKS = 'https://login.microsoftonline.com/common/discovery/v2.0/keys';

    public function connect(string $tenant, Request $request)
    {
        [$autnyxTenant, $conn] = $this->authorizedConnection($tenant);

        $clientId = (string) config('services.teams.client_id');
        if ($clientId === '' || (string) config('services.teams.client_secret') === '') {
            return $this->backWith($autnyxTenant, $conn, 'Microsoft 365 is not configured on this Autnyx environment (TEAMS_CLIENT_ID / TEAMS_CLIENT_SECRET).');
        }

        $state = Str::random(40);
        $nonce = Str::random(40);
        $request->session()->put('teams_consent', ['state' => $state, 'nonce' => $nonce, 'conn' => $conn->id, 'tenant' => $autnyxTenant->slug]);

        return redirect()->away(self::AUTHORITY . '/authorize?' . http_build_query([
            'client_id'     => $clientId,
            'response_type' => 'code',
            'response_mode' => 'query',
            'redirect_uri'  => route('teams.consent.callback'),
            'scope'         => 'openid profile https://graph.microsoft.com/.default',
            'prompt'        => 'admin_consent',
            'state'         => $state,
            'nonce'         => $nonce,
        ]));
    }

    public function callback(Request $request)
    {
        $flow = (array) $request->session()->pull('teams_consent', []);
        if (empty($flow['state']) || ! hash_equals((string) $flow['state'], (string) $request->query('state'))) {
            abort(403, 'Microsoft 365 connection state check failed. Please start again.');
        }

        [$autnyxTenant, $conn] = $this->authorizedConnection((string) $flow['tenant']);
        abort_unless((int) $conn->id === (int) $flow['conn'], 403);

        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->backWith($autnyxTenant, $conn, 'Microsoft 365 did not grant consent: ' . Str::limit((string) ($request->query('error_description') ?: $request->query('error')), 200));
        }

        try {
            $resp = Http::asForm()->acceptJson()->timeout(15)->withoutRedirecting()->post(self::AUTHORITY . '/token', [
                'grant_type'    => 'authorization_code',
                'code'          => (string) $request->query('code'),
                'redirect_uri'  => route('teams.consent.callback'),
                'client_id'     => (string) config('services.teams.client_id'),
                'client_secret' => (string) config('services.teams.client_secret'),
                'scope'         => 'openid profile https://graph.microsoft.com/.default',
            ]);
            if (! $resp->successful() || ! $resp->json('id_token')) {
                throw new \RuntimeException('token exchange failed (HTTP ' . $resp->status() . ')');
            }

            $claims = app(IdTokenVerifier::class)->verify((string) $resp->json('id_token'), function (bool $refresh): array {
                if ($refresh) {
                    Cache::forget('teams_ms_jwks');
                }

                return Cache::remember('teams_ms_jwks', 3600, fn () => (array) Http::acceptJson()->timeout(10)->get(self::JWKS)->json());
            });

            $tid = (string) ($claims['tid'] ?? '');
            $aud = $claims['aud'] ?? null;
            $ok = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tid)
                && $aud === (string) config('services.teams.client_id')
                && hash_equals((string) $flow['nonce'], (string) ($claims['nonce'] ?? ''))
                && (int) ($claims['exp'] ?? 0) + 60 >= time()
                && rtrim((string) ($claims['iss'] ?? ''), '/') === "https://login.microsoftonline.com/{$tid}/v2.0";
            if (! $ok) {
                throw new \RuntimeException('the Microsoft sign-in token did not validate');
            }
        } catch (Throwable $e) {
            return $this->backWith($autnyxTenant, $conn, 'Could not verify the Microsoft 365 sign-in: ' . $e->getMessage());
        }

        $conn->forceFill(['aad_tenant_id' => strtolower($tid), 'aad_verified_at' => now()])->save();

        AuditLog::create([
            'tenant_id'   => $autnyxTenant->id,
            'user_id'     => auth()->id(),
            'event_type'  => 'teams_tenant_verified',
            'description' => 'Microsoft 365 tenant ' . strtolower($tid) . ' linked to Teams connection #' . $conn->id . ' by signed admin consent.',
        ]);

        return $this->backWith($autnyxTenant, $conn, null);
    }

    /** @return array{0: Tenant, 1: TeamsConnection} */
    private function authorizedConnection(string $slug): array
    {
        $tenant = Tenant::where('slug', $slug)->firstOrFail();
        $user = auth()->user();
        abort_unless($user && $user->canAccessTenant($tenant) && $user->canManageUsers(), 403);

        $conn = TeamsConnection::where('tenant_id', $tenant->id)->firstOrFail();

        return [$tenant, $conn];
    }

    private function backWith(Tenant $tenant, TeamsConnection $conn, ?string $error)
    {
        $url = \App\Filament\Resources\TeamsConnectionResource::getUrl('edit', ['record' => $conn, 'tenant' => $tenant]);

        return redirect($url)->with($error ? 'teams_consent_error' : 'teams_consent_ok', $error ?? 'Microsoft 365 tenant linked.');
    }
}
