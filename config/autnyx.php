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

];
