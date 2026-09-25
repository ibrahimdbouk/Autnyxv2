# Migrations on a live database

Laravel Cloud runs `php artisan migrate --force` on every deploy, while the app,
the queue worker and imports keep writing. A migration that takes a strong lock
on a busy table blocks every writer behind it until it finishes. These rules
keep a deploy from freezing the app.

## The rules

1. **Lock timeout.** Every migration runs with `lock_timeout` =
   `database.migration_lock_timeout` (10s, env `DB_MIGRATION_LOCK_TIMEOUT`),
   set by a `MigrationsStarted` listener. If a long transaction holds the
   table, the migration fails and the deploy fails, rather than queueing all
   writers behind it. Re-deploy when the table is quiet (not during a tenant's
   01:00–04:00 local nightly window).

2. **Indexes on hot tables are built concurrently.** Use
   `App\Support\Database\ConcurrentIndex::create()` / `::drop()` in a
   migration with `public $withinTransaction = false;`. Never
   `$table->index()` / `->unique()` inside `Schema::table()` on a hot table,
   and never a raw `CREATE INDEX` without `CONCURRENTLY`. The helper is
   idempotent and rebuilds an index an interrupted build left INVALID.
   Template: `stubs/migration.concurrent-index.stub`.

3. **One concern per migration.** Keep index builds in their own migration
   (no transaction), separate from column changes (in a transaction).

4. **Adding a column** to a hot table: nullable, or with a constant default
   (PostgreSQL stores it without rewriting the table). Backfill with an
   artisan command in chunks, never inside the migration.

5. **Foreign keys on hot tables:** add them `NOT VALID`, then
   `VALIDATE CONSTRAINT` in a separate statement (the validation takes only a
   share-update lock).

6. **Changing a column type** rewrites the table unless it only widens the
   precision of a `numeric` with the same scale or widens a `varchar`. Plan
   rewrites for a maintenance window.

7. **Data repairs are artisan commands**, never migrations: dry run by
   default, `--apply` to write, and a backup table for anything deleted.

## Hot tables

`sales_transactions`, `sales_daily`, `inventory_levels`, `inventory_current`,
`purchase_orders`, `sales_returns`, `anomalies`, `investigations`,
`investigation_entities`, `investigation_evidence`, `products`,
`sku_profiles`, `sku_baselines`, `sku_replenishment`, `quarantined_rows`,
`import_rows`.

`MigrationConventionTest` enforces rules 1–2 for migrations from 2026-09-25
onwards.
