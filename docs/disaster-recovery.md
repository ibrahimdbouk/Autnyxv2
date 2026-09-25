# Disaster recovery runbook (WP8.1)

**Scope:** the production PostgreSQL database (Laravel Cloud Serverless Postgres, eu-central-1) and the object-storage bucket.

**Owner:** the platform owner (Ibrahim). Keep a second person able to follow this runbook.

## Targets

| Scenario | Recovery path | RPO (data lost) | RTO (back in service) |
|---|---|---|---|
| Bad deploy, bad data repair, accidental delete (inside the PITR window) | **Point-in-time restore** (Laravel Cloud) | Seconds, to the chosen instant | About 30 min, to be measured by the first console drill (see "Drills") |
| Mistake older than the PITR window; PITR not configured; lost cluster or project | **Nightly logical backup** (`db:backup`) | Up to 24 h (backup at 03:15 UTC) | **37 s restore measured** on 2026-09-25, plus about 15 min to repoint and redeploy |
| Lost object storage (import files) | Re-upload the source files | Import files only; imported rows stay in the database | — |

**Import files (W9 correction):** until 2026-09-25, uploads were written to the app container's local disk, which is ephemeral, so files from before then are gone (their rows are in the database). Since W9 they go to the private bucket (`AUTNYX_STORAGE_DISK`, falling back to `FILESYSTEM_DISK`). The hourly health check flags a production app that stores uploads locally.

## Layer 1: Point-in-time recovery (Laravel Cloud)

- Laravel Cloud keeps a continuous log of changes for a configurable **retention window of 0 to 30 days** (Resources → Databases → the database → **Backups**). A restore never touches production: it creates a **separate cluster**.
- **Confirm now:** the retention window is set, and to at least 7 days (30 recommended). The API token used by our tooling cannot read cluster settings, so this has to be checked in the console. Record the value here: `____ days, checked ____`.
- **Restore:**
  1. Cloud → Databases → Backups → restore to a point in time.
  2. Wait for the new cluster.
  3. In the environment, point `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` (and `DB_OWNER_*`) at it, or attach it.
  4. Redeploy.
  5. Verify (below).

## Layer 2: Nightly logical backups (ours)

- `db:backup` runs daily at **03:15 UTC**. It writes `pg_dump --format=custom` to the object-storage bucket under `backups/db/`, outside the database cluster, with a `.json` manifest (sha256, size, server version, last migration, table estimates).
- Retention: every backup for 14 days, then one per week for 8 weeks.
- The hourly health check alerts if there has been no successful backup for 30 h.
- First production backup: 2026-09-25, **104 tables, 50 MB** (database 809 MB), taken in **9.5 s**.
- List backups: `php artisan db:backup --list`.

**Restore into a new database** (the drill does steps 1 and 2 for you and drops the result):

1. Create an empty database, either on the same cluster (`CREATE DATABASE autnyx_restore`) or on a new Cloud database.
2. Restore:
   ```
   pg_restore --no-owner --no-acl --jobs=4 --exit-on-error --dbname=<target> <file.dump>
   ```
   The file comes from the bucket: `php artisan tinker` → `Storage::disk(config('backup.disk'))->readStream(...)`, or download it in the Cloud console.
3. Repoint the environment's `DB_*` variables at the restored database and redeploy.
4. Re-run `php artisan db:runtime-role` if the runtime role is in use, because grants are not part of the restore (`--no-acl`).
5. Verify (below).

## Verify after any restore

1. `php artisan migrate:status`: every migration ran; nothing pending unless the code is newer.
2. `php artisan db:integrity`: no duplicates, no orphan rows.
3. `php artisan security:secrets` and `php artisan db:runtime-role --check`.
4. Sign in, open the dashboard, an investigation and the Action Center for each tenant.
5. Anything written between the restore point and now is gone. Re-import the files received since then; they are still in the bucket for 90 days. Then run `php artisan nightly:dispatch --tenant=<id> --force` per tenant to rebuild detection.
6. Tell the affected tenants what window was lost.

## Drills

- `php artisan db:restore-drill` downloads the latest backup, checks its sha256, restores it into a scratch database on the same server, compares **every table's exact row count** and the migrations with the live database, then drops the scratch database.
- **2026-09-25 (first drill, production):** 104 of 104 tables, **2,718,886 rows restored of 2,718,886 live**, 159 of 159 migrations, checksum OK; download 1.2 s, restore 28.7 s, **37 s in total**.
- **Quarterly:** run `db:restore-drill`, and once a year a PITR restore to a new cluster in the console (to measure the Layer 1 RTO). Record the date and timings here.

| Date | Drill | Result | Time |
|---|---|---|---|
| 2026-09-25 | db:restore-drill (latest nightly) | 104/104 tables, row counts identical | 37 s |

## Notes

- **Erased tenants and backups:** a tenant erased through offboarding (WP6.7) still exists in backups until they age out: at most 8 weeks for logical backups, plus the PITR window. State this in the DPA.
- **Access:** backups contain every tenant's data. The bucket is private with server-side encryption. Only the platform owner holds the Cloud console and bucket access.
- **Database roles:** see docs/database-roles.md. Migrations, backups and drills run as the owner role; the app runs as the least-privilege runtime role.
