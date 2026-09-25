<?php

namespace App\Support\Security;

/**
 * WP8.1 (audit L13) — every secret the platform depends on, where it lives,
 * who can rotate it and how often. Names only; values never pass through
 * here. docs/secrets-inventory.md is the readable copy and carries the last
 * rotation dates; `security:secrets` reports which are set and flags
 * hygiene problems.
 */
final class SecretsInventory
{
    /**
     * key => [purpose, where it lives, rotate every N days (0 = on exposure only), config path to test presence or null]
     *
     * @var array<string,array{0:string,1:string,2:int,3:?string}>
     */
    public const SECRETS = [
        'APP_KEY'              => ['Encrypts sessions, cookies and encrypted columns (SSO, API, SFTP, Teams, outbound credentials)', 'Laravel Cloud env', 365, 'app.key'],
        'DB_PASSWORD'          => ['Runtime database role (least privilege once WP8.1 is rolled out)', 'Laravel Cloud env / database', 90, 'database.connections.pgsql.password'],
        'DB_OWNER_PASSWORD'    => ['Database owner role — migrations, backups, restore drills only', 'Laravel Cloud env / database', 90, 'database.owner.password'],
        'DB_RUNTIME_PASSWORD'  => ['Input to db:runtime-role when the runtime role is (re)provisioned', 'Laravel Cloud env (remove after use)', 90, null],
        'ANTHROPIC_API_KEY'    => ['AI narration, agents and mapping', 'Laravel Cloud env · console.anthropic.com', 90, 'services.anthropic.key'],
        'MAIL_PASSWORD'        => ['Resend SMTP API key (digests, alerts)', 'Laravel Cloud env · resend.com', 180, 'mail.mailers.smtp.password'],
        'COMPOSER_AUTH'        => ['GitHub token used by the build to install private packages', 'Laravel Cloud env (build) · github.com', 90, null],
        'TEAMS_CLIENT_SECRET'  => ['Microsoft 365 app used to bind Teams connections', 'Laravel Cloud env · Entra ID app registration', 365, 'services.teams.client_secret'],
        'INBOUND_EMAIL_SECRET' => ['Shared secret on the inbound-email webhook (unset = endpoint off)', 'Laravel Cloud env + email provider', 180, 'inbound.secret'],
        'HEARTBEAT_URL'        => ['Dead-man switch URL pinged by the hourly health check', 'Laravel Cloud env · monitor', 0, 'observability.heartbeat_url'],
        'AWS_SECRET_ACCESS_KEY'=> ['Object-storage bucket (imports, backups)', 'Managed by Laravel Cloud (attached bucket)', 0, null],
        'OWNER_PASSWORD'       => ['First-deploy password of the platform owner (bootstrap only)', 'Laravel Cloud env — remove after first deploy', 0, null],
        'ADMIN_PASSWORD'       => ['Password of the seeded admin@autnyx.io account on first creation (bootstrap only)', 'Laravel Cloud env — remove after first deploy', 0, null],
    ];

    /** Outside the app's environment, still part of the inventory. */
    public const EXTERNAL = [
        'GitHub push credential on the dev PC (auto-push)' => ['Pushes main', 'Windows Credential Manager', 90],
        'Laravel Cloud API token (ops tooling)'            => ['Runs commands and reads the environment', 'cloud.laravel.com › API tokens', 90],
        'Tenant-held credentials (SFTP, API, SSO, Teams, outbound targets)' => ['Tenant integrations', 'Database, encrypted with APP_KEY', 0],
        'Public API keys issued to tenants'                => ['Tenant API access', 'Database (hashed); tenants revoke and reissue', 0],
    ];

    /** The seeded admin's historical default password (DatabaseSeeder before WP8.1). */
    public const OLD_ADMIN_DEFAULT = 'Autnyx2026!';
}
