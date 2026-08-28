<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SsoConnection;
use App\Models\Tenant;
use App\Services\Sso\OidcService;
use App\Services\Sso\SsoUserProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * 1b — per-tenant OIDC single sign-on.
 *
 * Flow: /sso/start (enter email) → discover the tenant by email domain →
 * /sso/{tenant}/redirect (bounce to the IdP with state+nonce) →
 * /sso/{tenant}/callback (exchange code, validate, sign in). Password login on
 * the Filament panel stays available as an admin break-glass path.
 */
class SsoController extends Controller
{
    public function __construct(
        private readonly OidcService $oidc,
        private readonly SsoUserProvisioner $provisioner,
    ) {}

    /** The email-entry page that discovers a tenant's SSO by domain. */
    public function start(Request $request)
    {
        return view('sso.start', ['error' => $request->query('error')]);
    }

    /** Resolve which tenant's IdP to use from the submitted email domain. */
    public function discover(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = strtolower(trim($data['email']));
        $domain = substr($email, strpos($email, '@') + 1);

        $connection = SsoConnection::query()
            ->where('enabled', true)
            ->whereNotNull('allowed_domains')
            ->get()
            ->first(fn (SsoConnection $c) => in_array($domain, $c->allowedDomains(), true));

        if ($connection === null) {
            return redirect()->route('sso.start')
                ->with('error', 'No single sign-on is configured for that email domain.');
        }

        $tenant = $connection->tenant;

        return redirect()->route('sso.redirect', ['tenant' => $tenant->slug]);
    }

    /** Bounce the browser to the tenant's IdP. */
    public function redirect(string $tenant)
    {
        $connection = $this->enabledConnectionForSlug($tenant);
        if ($connection === null) {
            return redirect()->route('sso.start')->with('error', 'Single sign-on is not available for this organisation.');
        }

        $state = Str::random(40);
        $nonce = Str::random(40);

        $request = request();
        $request->session()->put('sso_state', $state);
        $request->session()->put('sso_nonce', $nonce);
        $request->session()->put('sso_tenant', $connection->tenant->slug);

        try {
            $url = $this->oidc->authorizationUrl(
                $connection,
                route('sso.callback', ['tenant' => $connection->tenant->slug]),
                $state,
                $nonce,
            );
        } catch (Throwable $e) {
            $this->audit($connection->tenant_id, AuditLog::EVENT_SSO_LOGIN_FAILED, 'SSO redirect failed: ' . $e->getMessage());

            return redirect()->route('sso.start')->with('error', 'Could not start single sign-on. Please contact your administrator.');
        }

        return redirect()->away($url);
    }

    /** Handle the IdP redirect back: validate, sign in, provision. */
    public function callback(string $tenant, Request $request)
    {
        $connection = $this->enabledConnectionForSlug($tenant);
        if ($connection === null) {
            return $this->fail(null, 'Single sign-on is not available for this organisation.');
        }

        // The IdP may report an error instead of a code.
        if ($request->filled('error')) {
            return $this->fail($connection->tenant_id, 'Identity provider returned an error: ' . $request->query('error'));
        }

        // CSRF: the state must match the one we stored before the redirect.
        $expectedState = $request->session()->pull('sso_state');
        $nonce         = $request->session()->pull('sso_nonce');
        $request->session()->forget('sso_tenant');

        if (! $expectedState || ! hash_equals($expectedState, (string) $request->query('state'))) {
            return $this->fail($connection->tenant_id, 'Single sign-on state check failed. Please try again.');
        }

        if (! $request->filled('code')) {
            return $this->fail($connection->tenant_id, 'Single sign-on did not return an authorization code.');
        }

        try {
            $tokens = $this->oidc->exchangeCode(
                $connection,
                (string) $request->query('code'),
                route('sso.callback', ['tenant' => $connection->tenant->slug]),
            );

            $claims = $this->oidc->claimsFromIdToken($tokens['id_token']);
            $this->oidc->validateIdToken($claims, $connection, (string) $nonce);

            $user = $this->provisioner->resolve($connection, $claims);
        } catch (Throwable $e) {
            return $this->fail($connection->tenant_id, 'Single sign-on failed: ' . $e->getMessage());
        }

        if ($user->wasRecentlyCreated) {
            $this->audit($connection->tenant_id, AuditLog::EVENT_SSO_PROVISIONED, 'Provisioned SSO user ' . $user->email, $user->id);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $this->audit($connection->tenant_id, AuditLog::EVENT_SSO_LOGIN, 'SSO login for ' . $user->email, $user->id);

        return redirect()->intended('/admin/' . $connection->tenant->slug);
    }

    // ── Internal ────────────────────────────────────────────────────────────

    private function enabledConnectionForSlug(string $slug): ?SsoConnection
    {
        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            return null;
        }

        $connection = $tenant->ssoConnection;

        return ($connection && $connection->enabled) ? $connection : null;
    }

    private function fail(?int $tenantId, string $message)
    {
        if ($tenantId !== null) {
            $this->audit($tenantId, AuditLog::EVENT_SSO_LOGIN_FAILED, $message);
        }

        return redirect()->route('sso.start')->with('error', $message);
    }

    private function audit(int $tenantId, string $event, string $description, ?int $userId = null): void
    {
        try {
            AuditLog::create([
                'tenant_id'   => $tenantId,
                'user_id'     => $userId,
                'event_type'  => $event,
                'description' => Str::limit($description, 250),
            ]);
        } catch (Throwable $e) {
            // best-effort — never break the auth flow on an audit write.
        }
    }
}
