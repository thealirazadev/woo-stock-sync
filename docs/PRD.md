# Product Requirements: woo-stock-sync

## What we're building

A WooCommerce plugin that synchronizes product stock quantities, regular prices, and sale prices
from a supplier feed into an existing store, keyed by SKU. The store owner configures a feed source
(an uploaded CSV file or a remote CSV/JSON URL fetched on a schedule, with optional auth header),
maps feed columns to product fields, and reviews every proposed change on a dry-run diff screen
before anything is written. Applying a sync runs in background batches via Action Scheduler so it
survives shared hosting; each row succeeds or fails independently with a recorded reason, prior
values are snapshotted so the last applied sync can be rolled back, and a full run history with
per-row results makes every change auditable. All admin actions have WP-CLI equivalents.

## Target user

Owners and managers of small-to-mid WooCommerce stores that resell from one supplier or wholesaler
who publishes inventory as a CSV or JSON feed. They update dozens to tens of thousands of products
at a time, run on ordinary shared hosting, and need to trust the tool: see what will change before
it changes, know exactly what happened afterwards, and undo a bad sync without restoring a database
backup. Secondary user: a developer or ops person scripting syncs over WP-CLI or cron.

## Core features (prioritized)

1. Dry-run diff before apply (highest priority). Every sync starts as a preview: a diff screen
   listing product, field, current value, new value, and planned action per row. Nothing is written
   until the owner (or an explicit auto-apply setting / CLI flag) applies the previewed run.
2. Feed source configuration. Choose an uploaded CSV file or a remote CSV/JSON URL; remote fetch
   supports one optional custom auth header (name + value). CSV is parsed by streaming, never
   loaded whole into memory.
3. Column mapping. Map feed columns to product fields: SKU (required key), stock quantity, regular
   price, sale price (at least one value field required). A blank cell means "no change" for that
   field; an explicit setting can treat a blank sale price as "clear the sale price". The mapping
   used is snapshotted onto each run for auditability.
4. Batched apply with partial-failure isolation. Applying runs as Action Scheduler batches sized
   for shared hosting. One bad row never aborts a batch: each row is processed independently and
   recorded as updated, skipped, or failed with a reason code (unknown SKU, invalid number, product
   locked, and so on), and the run summary reports the counts. A run that dies mid-batch is
   resumable, and a lock prevents two syncs from running concurrently.
5. Rollback of the last applied sync. Prior field values are snapshotted per product at apply time;
   the most recent applied run can be reverted in batches, with per-row rollback results recorded.
6. Locked products. A per-product "Lock from stock sync" flag; a sync never touches a locked
   product (or a variation of a locked parent) and records the row as skipped with reason locked.
7. Scheduled sync. A recurring Action Scheduler job fetches the remote feed on a configured
   interval (hourly, twice daily, daily, or manual only). Scheduled runs stop at the preview by
   default; an explicit setting enables auto-apply.
8. Sync history. A runs list (date, trigger, source, status, updated/skipped/failed counts) and a
   run detail screen with paginated, filterable per-row results.
9. WP-CLI commands mirroring the admin actions: fetch, dry-run, apply, rollback, and runs (history),
   with table/JSON output and meaningful exit codes.

## Non-goals / out of scope

- No multichannel marketplace sync (Amazon, eBay, or any channel other than the local store).
- No order or customer sync in either direction.
- No product creation from the feed: v1 is update-only. Unknown SKUs are reported, never created.
- No PIM features: no titles, descriptions, images, categories, or attributes from the feed.
- No multi-feed support: exactly one configured feed source in v1.
- No REST API routes in v1; the surface is admin screens (admin-post/admin-ajax) plus WP-CLI.
- No nested JSON path mapping; JSON feeds must be a flat array of objects with top-level keys.
- No rollback of anything except the most recent applied run (no arbitrary point-in-time restore).
- No direct SQL writes to products or product meta; all product writes go through WooCommerce CRUD.

## Success criteria per core feature

### 1. Dry-run diff before apply
- Fetching a feed produces a previewed run whose diff screen lists every row with product, field,
  current value, new value, and action; no product data changes until Apply is clicked.
- Rows with no difference between current and new values are shown as "no change" and are not
  written on apply.
- A previewed run can be cancelled without any product having changed.

### 2. Feed source configuration
- An uploaded CSV and a remote CSV URL both produce identical runs given identical content.
- A remote fetch sends the configured auth header; a 401/404/timeout fails the run with a clear
  run-level error and no partial staging.
- A CSV at the 50,000-row cap is parsed without exceeding a documented memory ceiling (streamed
  row by row); feeds above the cap fail fast with a clear error.

### 3. Column mapping
- Saving a mapping without a SKU column or without at least one value column is rejected with a
  field-level error.
- The diff and apply use exactly the mapped columns; unmapped feed columns are ignored.
- The run detail screen shows the mapping that was in effect when that run was created.

### 4. Batched apply with partial-failure isolation
- A feed containing an unknown SKU, a non-numeric price, and a locked product still applies every
  valid row; the three bad rows are recorded with reasons unknown_sku, invalid_number, and locked,
  and the summary counts match the row statuses exactly.
- Apply processes rows in batches (default 25) through Action Scheduler; killing the process
  mid-batch and resuming completes the run with no row applied twice and no row lost.
- Starting a second sync while one is applying is refused (admin notice / CLI error); the lock is
  released when the run finishes or fails, and a stale lock can be released from the admin.

### 5. Rollback of the last applied sync
- After applying a run, rolling it back restores the snapshotted stock, regular price, and sale
  price on every applied product, and the run status becomes rolled back with per-row results.
- Only the most recent applied run offers rollback; older runs do not.
- A product deleted between apply and rollback is recorded as rollback-failed with reason
  product_missing without aborting the rest of the rollback.

### 6. Locked products
- Checking "Lock from stock sync" on a product causes every subsequent sync to record its rows as
  skipped/locked and leave its stock and prices untouched, verified after an apply.
- Locking a variable product's parent locks all its variations.

### 7. Scheduled sync
- With an hourly schedule configured, a recurring Action Scheduler job creates a new previewed run
  each hour without any admin interaction; with auto-apply enabled, the run also applies.
- A scheduled fetch that finds the sync lock held skips cleanly and logs the reason; it does not
  queue up behind the running sync.

### 8. Sync history
- Every run (manual, scheduled, CLI) appears in the runs list with correct counts; the run detail
  screen pages through row results and filters by row status.
- History survives plugin deactivation/reactivation (custom tables are not dropped on deactivate).

### 9. WP-CLI
- `wp wss fetch`, `wp wss dry-run`, `wp wss apply`, `wp wss rollback`, and `wp wss runs` perform
  the same operations as the admin screens against the same tables, return exit code 0 on success
  and 1 on failure, and support `--format=table|json` where output is tabular.
