# Database roles (WP8.1)

Two roles, one database.

| Role | Used by | Can |
|---|---|---|
| **Owner** (`laravel` on Laravel Cloud) | `migrate*`, `db:backup`, `db:restore-drill`, `db:runtime-role`, `db:integrity`, `imports:dedupe-natural-keys`, `detection:load-test`, `schema:dump`, `db:wipe` | Everything: owns every table, creates databases and roles |
| **Runtime** (`autnyx_app`) | The web app, queue workers, the scheduler and every other command | Read and write rows of every table, use sequences and temporary tables. **No** DDL, no TRUNCATE, no ownership, no role or database creation |

How it works:

- `DB_USERNAME` / `DB_PASSWORD` are the runtime role.
- `DB_OWNER_USERNAME` / `DB_OWNER_PASSWORD` are the owner. When one of the owner commands above starts, `App\Support\Database\OwnerConnection` swaps the connection's credentials for that process only. With no owner configured, nothing changes: everything runs as `DB_USERNAME`, as before WP8.1.
- `db:runtime-role` (run as the owner) creates or refreshes the runtime role: the grants on every existing table, plus **default privileges**, so tables that later migrations create are usable by the runtime role at once.
- The hourly health check alerts if the runtime role ever lacks row access to a table ("run db:runtime-role").
- Verified end to end on a copy of the schema: the full nightly chain (aggregate, profiles, baselines with temporary tables, detection, narration, health, outcomes) and every main screen ran as the runtime role with no permission errors. `ResilienceTest` proves the role cannot `CREATE`, `ALTER` or `TRUNCATE`.

## Rolling it out on Laravel Cloud (once, about 10 minutes)

The passwords are typed only into the Cloud console; they never pass through a chat, a log or the repo.

1. **Environment → Variables**, add:
   - `DB_OWNER_USERNAME` = `laravel`;
   - `DB_OWNER_PASSWORD` = the current value of `DB_PASSWORD`;
   - `DB_RUNTIME_PASSWORD` = a new random password (32+ characters from a password manager).
2. **Commands**: `php artisan db:runtime-role`. It should end with `autnyx_app: least privilege — OK`.
3. **Environment → Variables**, change:
   - `DB_USERNAME` = `autnyx_app`;
   - `DB_PASSWORD` = the same value as `DB_RUNTIME_PASSWORD`;
   - then delete `DB_RUNTIME_PASSWORD`.
4. **Redeploy**. The deploy's `php artisan migrate --force` runs as the owner automatically.
5. **Check**: `php artisan security:secrets` must not report "connects as the database owner". Also run `php artisan db:runtime-role --check`.

**Roll back:** set `DB_USERNAME` / `DB_PASSWORD` back to the owner values and redeploy. The runtime role can stay; it is harmless.

**If Laravel Cloud re-injects `DB_USERNAME` from the database attachment** (step 5 still reports the owner), manage the `DB_*` variables yourself: detach the database from the environment and keep the variables above. The database itself is unaffected.

**Rotating:**

- Runtime role: set `DB_RUNTIME_PASSWORD` to a new value, run `db:runtime-role`, then put the same value in `DB_PASSWORD` and redeploy.
- Owner (`laravel`): reset it in Cloud → Database, then update `DB_OWNER_PASSWORD`.
