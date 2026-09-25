# Secrets inventory and rotation schedule (WP8.1)

**Names only. Never write a secret's value here, in a ticket, in a chat or in a log.**

`php artisan security:secrets` shows which of these are set (never their values) and flags hygiene problems:

- debug mode on in production;
- a weak `APP_KEY`;
- the seeded admin still on its old default password;
- the app connecting as the database owner.

The source list is `App\Support\Security\SecretsInventory`.

## Application environment (Laravel Cloud → Environment → Variables)

| Secret | What it protects | Rotate | How to rotate | Last rotated |
|---|---|---|---|---|
| `APP_KEY` | Sessions, cookies, and encrypted columns (SSO, API, SFTP, Teams and outbound credentials) | Yearly, or on exposure | Move the current key to `APP_PREVIOUS_KEYS`, set a new `APP_KEY` (`php artisan key:generate --show`), redeploy. Everyone is signed out; encrypted columns still decrypt through the previous key. | Never (since 2026-08-16) |
| `DB_PASSWORD` | Runtime database role | 90 days | docs/database-roles.md → Rotating | Never |
| `DB_OWNER_PASSWORD` | Owner database role (migrations, backups) | 90 days | Reset in Cloud → Database, then update the variable | Not yet set |
| `ANTHROPIC_API_KEY` | AI narration, agents, mapping | 90 days | console.anthropic.com → create key, set it, redeploy, delete the old key | Never |
| `MAIL_PASSWORD` | Resend SMTP (digests, alerts) | 180 days | resend.com → API keys → create, set, redeploy, revoke the old key | Never |
| `COMPOSER_AUTH` | GitHub token the **build** uses | 90 days (use an expiring, read-only fine-grained token) | github.com → Settings → Developer settings → fine-grained token with Contents: read on the private package repos only | Never |
| `TEAMS_CLIENT_SECRET` | Microsoft 365 app that binds Teams connections | 12 months (Entra caps at 24) | Entra ID → App registration → Certificates & secrets → new secret, set, redeploy, delete the old one | Not set |
| `INBOUND_EMAIL_SECRET` | Inbound-email webhook (unset = endpoint off) | 180 days | Set the new value here and at the email provider together | Not set |
| `HEARTBEAT_URL` | Dead-man switch ping | On exposure | Regenerate the check in the monitor | Not set |
| `DB_RUNTIME_PASSWORD` | Input to `db:runtime-role` only | Delete after use | docs/database-roles.md | — |
| `OWNER_PASSWORD`, `ADMIN_PASSWORD` | First-deploy passwords (bootstrap only) | Delete after first use | Since WP8.1 the seeder never falls back to a default password | — |
| `AWS_*` (object storage) | Imports and backups bucket | Managed by Laravel Cloud | Cloud → Object storage → rotate the keys | Managed |

## Outside the app's environment

| Secret | Where | Rotate |
|---|---|---|
| GitHub push credential on the dev PC (auto-push) | Windows Credential Manager | 90 days. Revoke the leaked `ghp_kz…` token (**W0**) |
| Laravel Cloud API token (ops tooling) | cloud.laravel.com → API tokens | 90 days |
| Tenant-held credentials (SFTP, API, SSO, Teams, outbound targets) | Database, encrypted with `APP_KEY` | By the tenant, on request or on exposure |
| Public API keys issued to tenants | Database (hashed) | By the tenant: revoke and reissue |

## Rules

- A secret that appears anywhere it shouldn't (a chat, a screenshot, a log, a ticket) is rotated that day, whatever its schedule.
- Bootstrap secrets (`OWNER_PASSWORD`, `ADMIN_PASSWORD`, `DB_RUNTIME_PASSWORD`) are removed once used.
- After each rotation, update the "Last rotated" column in this file.
- Review this file quarterly, together with the restore drill (docs/disaster-recovery.md).
