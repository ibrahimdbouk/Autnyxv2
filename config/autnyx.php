<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform owner
    |--------------------------------------------------------------------------
    |
    | The single protected "super-super-admin" account. Identified by email so
    | it survives re-seeding. The owner:
    |   • is always a super admin (cannot be demoted),
    |   • can never be deleted — not even by other super admins,
    |   • is the only account allowed to grant super-admin to others.
    |
    | The password is NEVER stored in the repo — it is seeded from OWNER_PASSWORD
    | (set in the environment / Laravel Cloud secrets) on first creation only.
    |
    */
    'owner_email' => env('OWNER_EMAIL', 'ibrahim@autnyx.com'),

    /*
    |--------------------------------------------------------------------------
    | Secure file storage (3a)
    |--------------------------------------------------------------------------
    |
    | The private disk that tenant uploads/exports are written to, always under a
    | per-tenant path prefix (see App\Services\Storage\TenantStorage). Defaults to
    | the local private disk; set AUTNYX_STORAGE_DISK=s3 (with AWS_* + SSE) to get
    | durable, server-side-encrypted, tenant-isolated object storage — no code
    | change. The local disk on Laravel Cloud is ephemeral, so S3 (or the
    | platform's private storage) is the intended production target.
    |
    */
    'storage_disk' => env('AUTNYX_STORAGE_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy (3b)
    |--------------------------------------------------------------------------
    |
    | 'report'  → send Content-Security-Policy-Report-Only (browsers evaluate and
    |             log violations to the console but never block — safe default;
    |             use it to validate the policy against every page).
    | 'enforce' → send Content-Security-Policy (blocks violations).
    | 'off'     → no CSP header.
    |
    | The policy allows what a Filament/Livewire/Alpine app needs: same-origin
    | assets, inline scripts/styles (Filament injects these), and Chart.js from
    | cdnjs — while locking down framing, base-uri, form-action, and objects.
    | 'unsafe-inline'/'unsafe-eval' are unavoidable with Alpine and can't be
    | tightened without CSP nonces (a larger change).
    |
    */
    'csp_mode' => env('CSP_MODE', 'report'),

    /*
    |--------------------------------------------------------------------------
    | Mandatory MFA for super admins (3b)
    |--------------------------------------------------------------------------
    |
    | When true, a super admin cannot reach the panels until they have enrolled
    | in TOTP MFA (Filament forces the set-up step at login). Regular users are
    | unaffected — MFA stays opt-in for them.
    |
    | Default FALSE so enabling MFA never locks anyone out on deploy: the owner
    | (a super admin) enrols first via avatar menu → Edit profile, THEN flips
    | MFA_REQUIRE_SUPER_ADMINS=true. The enforcement is fail-open — if the acting
    | user can't be resolved at evaluation time it does NOT require MFA, so a
    | misfire can never lock the owner out of break-glass.
    |
    */
    'require_mfa_super_admins' => env('MFA_REQUIRE_SUPER_ADMINS', false),

    /*
    |--------------------------------------------------------------------------
    | Anomaly digest email kill-switch (WP1.4)
    |--------------------------------------------------------------------------
    |
    | The nightly anomaly digest crashed on every send until WP1.4, so no tenant
    | has ever received one. It stays OFF until detection recalibration (W4)
    | removes the known false-positive floods (store_outlier, null-SKU churn) —
    | otherwise the first digests would email those to customers.
    |
    */
    'digest_enabled' => (bool) env('ANOMALY_DIGEST_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Outbound HTTP egress guard (WP2.2 — SSRF protection)
    |--------------------------------------------------------------------------
    | Every call to a tenant-configurable URL goes through App\Support\Http\EgressGuard.
    | resolve_dns — resolve + check every address and pin the connection to it
    |               (off only in the test suite, where hosts are faked).
    | allow_http  — permit plain http:// targets (never in production).
    */
    'egress' => [
        'resolve_dns' => (bool) env('EGRESS_RESOLVE_DNS', true),
        'allow_http'  => (bool) env('EGRESS_ALLOW_HTTP', false),
    ],

];
