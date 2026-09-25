# SOC 2 and ISO 27001 roadmap (WP10.7)

*Written 2026-09-25. A gap assessment of what Autnyx already has against SOC 2 (Trust Services Criteria, 2017 with 2022 points of focus) and ISO/IEC 27001:2022 (Annex A), and a phased plan to a first SOC 2 Type I, a Type II, and ISO 27001 certification. This is an engineering readiness view, not an audit opinion: the auditor or certification body decides scope and sufficiency.*

## The short version

The product side is further along than most companies at this stage. Tenant isolation is tested on every build, secrets are inventoried, there is an append-only audit log, SSO and MFA exist, CSP is enforced with nonces, backups are drilled, and retention is automated. What is missing is mostly the *management system* around it: written policies, a risk register, vendor reviews, access reviews, security training, change-management evidence, a pen test, and — the big one — **three to twelve months of evidence that the controls actually ran**. SOC 2 Type II and ISO 27001 both audit operation over time, so the clock only starts once the controls and their evidence trail exist.

Recommended order:

1. **Phase 0 (weeks 0–2): close the open owner actions.** Several are audit findings as they stand (unrotated secrets, a default admin password, the app connecting as the DB owner, no always-on worker).
2. **Phase 1 (weeks 2–8): write the ISMS.** Scope, policies, risk assessment, Statement of Applicability, vendor register.
3. **Phase 2 (weeks 4–10): automate evidence and fill the technical gaps.** Access reviews, change evidence, vulnerability scanning, logging and alerting, pen test.
4. **Phase 3 (month 3): SOC 2 Type I.** A point-in-time report customers can ask for, then a Type II observation window starts (3 months minimum for a first report; 6–12 is more common).
5. **Phase 4 (months 4–9): SOC 2 Type II report, and ISO 27001 Stage 1 and Stage 2** on the same control set (roughly 80% of the evidence is shared).

SOC 2 first is the usual order for a SaaS selling to retailers in the US and Gulf. It is attestation, not certification, and faster to a first report. ISO 27001 is often asked for by European and Gulf enterprise buyers; run it second, on the same evidence.

## Scope proposal

**In scope:** the Autnyx application (admin and ops panels, public API, import pipeline, detection, AI narration), its production environment on Laravel Cloud, the Neon Postgres database, private object storage, the GitHub repository and CI, and the people who can change or reach any of these.

**Subservice organisations (carve-out):**
- Laravel Cloud (hosting, runtime, managed database, object storage);
- Neon (Postgres);
- GitHub (source, CI);
- Anthropic (AI);
- Resend (email);
- Microsoft (Teams, Entra, when a tenant connects it).

For each one, collect its own SOC 2 report or ISO certificate and list the complementary user-entity controls it expects from us.

**Trust Services Criteria:** Security (mandatory) plus **Availability** and **Confidentiality**. Leave Processing Integrity and Privacy out of the first report. They add work without much buyer demand at this stage, and the product holds business data rather than consumer personal data (see the AI data-minimisation finding in the project notes).

## What exists today, mapped

| Area | What Autnyx has (evidence) | SOC 2 | ISO 27001:2022 Annex A | Status |
|---|---|---|---|---|
| Logical access | Per-tenant SSO (OIDC); password login as break-glass; super-admin control plane; per-user screen visibility; API keys per tenant | CC6.1, CC6.2, CC6.3 | 5.15, 5.16, 5.18, 8.2, 8.5 | **Built.** No periodic access review yet. |
| MFA | TOTP plus recovery codes on both panels; secrets encrypted at rest; `MfaPolicy` can make it mandatory for super admins | CC6.1 | 8.5 | **Built, not enforced.** `MFA_REQUIRE_SUPER_ADMINS` is off. |
| Tenant isolation | Query- and controller-level scoping; `TenantIsolationTest`, `TenantScopingIntegrityTest`, `CrossTenantMatrixTest` run on every CI build | CC6.1, C1.1 | 8.3, 8.11 (partly) | **Strong.** Keep as a named key control. |
| Encryption | TLS with HSTS; encrypted columns (SSO, API, SFTP, Teams, outbound credentials); private object storage with server-side encryption | CC6.1, CC6.7, C1.1 | 8.24 | **Built.** Confirm DB-at-rest encryption from the Neon and Cloud reports. |
| Application security | Nonce-based CSP, enforced (`CSP_MODE=enforce`, test-guarded); Referrer, Permissions and HSTS headers; PII guard on imports; architecture-boundary test | CC6.6, CC6.8, CC8.1 | 8.26, 8.28 | **Built.** No SAST, dependency or container scanning yet. |
| Secrets | `docs/secrets-inventory.md`; `security:secrets` hygiene check; rotation schedule | CC6.1 | 5.17, 8.24 | **Documented.** Rotation overdue (owner actions 1–3). |
| Least privilege (DB) | `docs/database-roles.md`; `db:runtime-role` | CC6.3 | 8.2, 8.18 | **Ready, not applied.** Production still connects as the owner role. |
| Audit logging | Append-only `audit_logs` (access changes, SSO, impersonation, tenant lifecycle, exports, outcome audits); Nightwatch for errors | CC7.2, CC4.1 | 8.15, 8.16 | **Partial.** No central log retention/alerting policy; admin reads not logged. |
| Change management | CI (tests must pass) fast-forwards `production`; named commits; migrations convention test; `docs/shipping.md` | CC8.1 | 8.25, 8.32 | **Partial.** Needs branch protection, required review and a change record per release. |
| Backups and DR | PITR (Laravel Cloud); nightly logical backups; `db:restore-drill`; `docs/disaster-recovery.md` with RPO/RTO | A1.2, A1.3 | 5.29, 5.30, 8.13, 8.14 | **Built.** Needs quarterly drill evidence and a written BCP. |
| Monitoring and availability | `system:health`; heartbeat (dead-man switch) support; Ops console; statement timeouts | A1.1, CC7.1, CC7.2 | 8.6, 8.16 | **Partial.** Heartbeat not configured; no uptime SLO or on-call. |
| Incident response | `claude/incidents.md` incident log (INC-xxx) | CC7.3, CC7.4, CC7.5 | 5.24–5.28 | **Informal.** Needs a written IR plan, severity levels, customer notification terms. |
| Data retention and disposal | `config/retention.php` plus nightly `data:purge`; tenant erasure (`TenantOffboardingService`) | C1.2, CC6.5 | 5.33, 8.10 | **Built.** Backup persistence (8 weeks plus PITR) must be stated in the DPA. |
| Vendor management | None written | CC9.2 | 5.19–5.23 | **Gap.** |
| Risk assessment | None written | CC3.1–CC3.4 | 6.1 (clause), 8.2 (clause) | **Gap.** |
| Policies and governance | Engineering docs only | CC1.x, CC2.x, CC5.3 | 5.1–5.4, clauses 4–10 | **Gap.** |
| People security | None (small team) | CC1.4, CC2.2 | 6.1–6.8 | **Gap.** Background checks, NDAs, training, onboarding and offboarding. |
| Endpoint and physical | Hosted in the cloud; no office controls in scope | CC6.4, CC6.8 | 7.x, 8.1 | **Gap (endpoint).** Physical controls are inherited from the providers. |

## Phase 0: close what an auditor would find today (weeks 0–2)

These are already on `claude/owner-actions.md`; they matter for compliance, not just hygiene:

1. **Rotate** `DB_PASSWORD`, `ANTHROPIC_API_KEY`, `MAIL_PASSWORD`, `COMPOSER_AUTH` and `APP_KEY`; revoke the leaked PAT; change the seeded admin password. Record each date in `docs/secrets-inventory.md`.
2. **Protect `production`** so only CI can push, and require one review on `main` (CC8.1, A.8.32).
3. **Apply the runtime database role** (`docs/database-roles.md`) (CC6.3, A.8.2).
4. **Enrol MFA, then set `MFA_REQUIRE_SUPER_ADMINS=true`.** Also require MFA or SSO for every tenant admin (CC6.1, A.8.5).
5. **Run the queue on an always-on worker, and set `HEARTBEAT_URL`** (A1.1, A.8.6).
6. **Set PITR retention** (30 days recommended) and log a restore drill (A1.3, A.8.13).
7. **Add Dependabot** (CC7.1, A.8.8).

## Phase 1: the management system (weeks 2–8)

Documents to write (a compliance platform ships templates for most of them):

- **ISMS scope and context** (ISO clause 4). This matches the scope above.
- **Information security policy** (umbrella), signed by the CEO.
- **Topic policies:**
  - acceptable use;
  - access control;
  - cryptography and key management;
  - secure development and change management;
  - logging and monitoring;
  - incident response;
  - business continuity and DR;
  - backup;
  - data classification and retention;
  - vendor management;
  - HR security;
  - endpoint;
  - AI use (what goes to Anthropic, and what does not).
- **Risk methodology and risk register.** Likelihood × impact, with owners and treatment. Starter risks:
  - cross-tenant leak;
  - credential leak;
  - an AI provider holding data;
  - a feed outage going unnoticed;
  - wrong recommendations (detection precision);
  - single-person dependency (key person);
  - a hosting-provider outage.
- **Statement of Applicability** (ISO): all 93 Annex A controls, with applicable or excluded and a reason for each. Physical controls (7.x) are mostly inherited.
- **Vendor register.** For each subservice organisation: data shared, its SOC 2 or ISO report, the DPA, and the review date.
- **Roles.** Name a security owner, even if that is the founder, and state who approves access, changes and exceptions.
- **Customer-facing documents:**
  - DPA (including backup persistence);
  - sub-processor list;
  - security page;
  - SLA / uptime commitment (drives the Availability criteria).

## Phase 2: evidence and technical gaps (weeks 4–10)

Claude can build most of these in the product or the repo. Items marked **(owner)** need you or a vendor.

1. **Quarterly access review, in-app.** An Ops report listing every user per tenant and super admin, with role, last login, MFA or SSO status and API keys. The reviewer signs it off, the sign-off goes into `audit_logs`, and an export is kept as evidence (CC6.2, A.5.18).
2. **Log admin reads.** Impersonation is already recorded; add super-admin views of tenant data and views of sensitive tenant screens. Set audit log retention to at least 1 year (already longer than raw data) (CC7.2, A.8.15).
3. **Security alerting.** Alerts to Teams or email on:
   - repeated login failures;
   - MFA disabled;
   - new super admin;
   - API key created;
   - bulk export;
   - health check failing.

   Most of these events are already audited; this adds alerts (CC7.2, CC7.3, A.8.16).
4. **Change record per release.** CI writes the commit range, test result and deployer to a `releases` table and the Ops console. That gives an auditor sampleable evidence for CC8.1 (A.8.32).
5. **Vulnerability management:**
   - Dependabot (owner, Phase 0);
   - `composer audit` and `npm audit` in CI, failing on high severity;
   - a static analysis pass (Larastan) at a baseline level;
   - written patch SLAs: critical 7 days, high 30 (CC7.1, A.8.8).
6. **Annual penetration test (owner).** An external firm, with tenant-isolation and API testing in scope. Track remediation in the incident/issue log (CC4.1, A.8.8).
7. **Incident response runbook.** Severity levels, who decides, the customer-notification clock (the DPA usually says 72 hours; GDPR sets 72 hours to the authority), a post-mortem template, and one tabletop exercise per year (CC7.4, A.5.24–5.27).
8. **BCP.** One page on top of the DR runbook: hosting-provider outage, key-person loss, AI provider outage (narration degrades; detection does not depend on it) (A1.2, A.5.29–5.30).
9. **People (owner):**
   - security awareness training at hire and yearly;
   - NDAs and confidentiality clauses;
   - background checks where lawful;
   - an onboarding and offboarding checklist, including GitHub, Cloud, Neon and Anthropic access.
10. **Endpoints (owner).** Disk encryption, screen lock, OS updates and a password manager on every laptop that can reach production. A compliance platform's agent can collect this evidence.

## Phase 3 and 4: audits

- **Pick a compliance automation platform.** Vanta, Drata, Secureframe and Sprinto are the common ones. Each connects to GitHub and the cloud accounts, collects evidence continuously and ships policy templates. Check that it can integrate with Laravel Cloud and Neon, or plan for manual evidence uploads there.
- **Pick an auditor.** A licensed CPA firm for SOC 2, an accredited certification body for ISO 27001. Many firms do both, which cuts cost when the control sets are shared.
- **Readiness assessment.** Most platforms and auditors offer one. Do it at the end of Phase 2.
- **SOC 2 Type I**, then a Type II observation window: 3 months minimum for a first report, 6–12 months is common. The window starts only once every control is operating.
- **ISO 27001:**
  - one internal audit and one management review (clause 9) before Stage 1;
  - Stage 1 (documentation);
  - Stage 2 (operation), a few weeks later;
  - surveillance audits every year, recertification every three years.

## What this means for sales before the reports exist

Until the Type I report lands, answer security questionnaires from this document. Offer:

- the architecture and isolation tests;
- the DR runbook;
- the secrets inventory (names only);
- the DPA;
- the sub-processor list.

A mutual NDA plus a short security overview (a 2-page extract of this file) covers most early enterprise buyers.

## Rough timeline

| Month | Milestone |
|---|---|
| 0–0.5 | Phase 0 owner actions done |
| 0.5–2 | Policies, risk register, SoA, vendor register; compliance platform connected |
| 1–2.5 | Access review, alerting, release records, CI scanning, IR runbook; pen test booked |
| ~3 | Readiness assessment → SOC 2 Type I |
| 3–6 (or 9) | Type II observation window; ISO internal audit and management review |
| ~6–9 | SOC 2 Type II report; ISO Stage 1 → Stage 2 → certificate |

The dates are indicative. The binding constraint is evidence over time, not engineering.
