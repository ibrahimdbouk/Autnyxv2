# Fact-table partitioning (decision D9)

**Status:** designed, not applied. Apply when the first tenant passes about
50 million `sales_transactions` rows, or when the nightly `data:purge` of
sales lines takes longer than 30 minutes, whichever comes first.

## Why partition at all

Retention (`data:purge`) deletes old rows in bounded batches. That works to a
few hundred million rows, but every deleted row is dead space until vacuum,
and the (tenant, date) indexes keep growing with churn. Range partitions by
month turn "delete a month" into `DROP TABLE` / `DETACH PARTITION`: instant,
no dead rows, no index bloat. Queries that filter on date (every rule and
page does, through `Window` and the default date filters) touch only the
partitions they need.

## What gets partitioned

| Table | Partition key | Scheme | Why |
|---|---|---|---|
| `sales_transactions` | `date` | RANGE, monthly | biggest table; purged at 395 days |
| `sales_daily` | `date` | RANGE, monthly | read by every rule over windows; purged at 550 days |
| `inventory_levels` | `as_of_date` | RANGE, monthly | history only since WP6.2; purged at 395 days |
| `platform_events` | `occurred_at` | RANGE, monthly | append-only |

Not partitioned: `inventory_current` (one row per position, bounded),
`purchase_orders` and `sales_returns` (orders of magnitude smaller),
`anomalies` / `investigations` (never purged; small).

**Not by tenant.** Hash or list partitioning by `tenant_id` was considered and
rejected: tenants differ in size by 1,000×, so partitions would be badly
skewed, and every query already leads with `tenant_id` in the index. Months
line up with how data ages and is purged.

## Constraints PostgreSQL imposes

1. **The partition key must be in every unique index.** Today:
   - `sales_tx_receipt_line_unique (tenant_id, transaction_id, line_no)` →
     becomes `(tenant_id, transaction_id, line_no, date)`. A receipt's lines
     share one date, so the key still identifies a line. The import writer's
     `ON CONFLICT` target changes to match.
   - `sales_daily_unique (tenant_id, store_id, sku, date)` already contains
     `date`.
   - `inventory_levels_natural_key (tenant_id, store_id, sku, as_of_date, batch_ref)`
     already contains `as_of_date`.
   - The `id` primary key becomes `(id, date)` / `(id, as_of_date)`.
2. **No foreign keys may point at a partitioned table's `id`.** Nothing
   references `sales_transactions.id` or `inventory_levels.id` today; keep it
   that way (evidence rows store the values they cite, not row ids).
3. **Rows need a non-null key.** `sales_transactions.date` is NOT NULL;
   `inventory_levels.as_of_date` is filled with the import date when missing
   (WP3.5). A DEFAULT partition catches anything else and is alerted on.

## Migration plan (online, per table)

1. Create `<table>_p` as `PARTITION BY RANGE (<key>)` with the new keys,
   monthly partitions from the oldest retained month to 3 months ahead, and a
   DEFAULT partition.
2. Copy in monthly batches (`INSERT … SELECT … WHERE key in month`) while the
   app keeps writing to the old table; a trigger on the old table mirrors
   inserts / updates / deletes into `<table>_p` for rows at or after the copy
   watermark.
3. Verify counts and sums per tenant and month (`db:partition-verify`).
4. In one short transaction: take the lock, drop the trigger, rename
   `<table>` → `<table>_old`, `<table>_p` → `<table>`, reset the sequence.
5. Keep `<table>_old` for 7 days, then drop it.

A scheduled `db:partitions:ensure` (monthly) creates the next 3 months'
partitions; `data:purge` then drops partitions wholly older than the window
instead of deleting rows (row-by-row purge stays for the tables not
partitioned).

## Until then

- Retention runs per tenant on the (tenant_id, date) indexes (WP6.6).
- `sales_daily` is never rebuilt for days older than the raw retention
  window, so aggregates outlive their purged lines (WP6.6).
- Detection splits a big tenant into SKU buckets (WP6.3) and all history
  reads are windowed.
