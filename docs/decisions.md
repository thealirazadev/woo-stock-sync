# Design decisions

## 1. Every sync is a run with an explicit lifecycle and a dry-run first

A sync is a row in `wss_runs` that moves through `pending`, `fetching`, `diffing`, `previewed`, then `applying` and `applied`, with `cancelled`, `failed`, `rolling_back` and `rolled_back` as the other states. Nothing touches a product until a `previewed` run is applied, so the diff the owner reviews is the plan that will be executed. Scheduled runs also stop at the preview unless the `auto_apply` scheduled mode is chosen. The plugin is update-only: an SKU with no matching product is reported as `skipped` / `unknown_sku` and never created, because creating products pulls in titles, images, categories and tax decisions that do not belong to a stock syncer.

## 2. Audit data lives in custom tables, products are written only through WooCommerce CRUD

Runs, per-row results and snapshots are stored in three narrow tables (`wss_runs`, `wss_rows`, `wss_snapshots`) created by `dbDelta` and versioned by `wss_db_version`. A daily 10,000-row feed would otherwise write millions of post meta rows with no efficient way to page results, filter by status or prune history. `wss_rows` is indexed on `(run_id, status, id)`, which is exactly the shape of the batch cursor query. The only post meta is `_wss_locked`, which really is a product attribute. Product changes always go through `wc_get_product()`, the setters and `save()`, never SQL or `update_post_meta`, so WooCommerce's caches, the `wc_product_meta_lookup` table, stock status transitions and third-party hooks stay consistent.

## 3. Work runs in Action Scheduler batches of 25 rows

Fetching, diffing, applying and rolling back all run inside Action Scheduler actions, never in an admin request. A batch is 25 rows by default (`WSS_DEFAULT_BATCH_SIZE`, filter `wss_batch_size`): each row costs a product load and a CRUD save, and 25 of those finish in a few seconds, well inside a shared host's execution limit. Action Scheduler ships with WooCommerce, persists jobs and has its own admin screen, so it adds no dependency. Staging inserts feed rows 250 at a time in one prepared multi-row `INSERT` so a 50,000-row fetch fits in a single action. The row cap (50,000), the JSON size cap (20 MB) and the batch size are constants with filters rather than settings, so a store owner cannot configure the plugin into an out-of-memory or timeout failure.

## 4. Batches are resumable because the cursor lives in the database

A batch action carries only the run ID. Each batch selects the next rows still in the source status (`staged` for diff, `planned` for apply) ordered by `id`, and every row reaches a terminal status as soon as it is processed. If a worker dies, scheduling one more batch continues exactly where it stopped and no row is processed twice. `WSS_Runner::is_stalled()` treats a run as stalled when no row has changed for 10 minutes and no action is pending for it, which is when the run screen offers Resume; `wp wss apply --run=<id>` resumes the same way.

## 5. One sync at a time, enforced by an atomic option

The lock is `add_option( 'wss_active_run', $run_id )`, which is atomic because `option_name` is unique, so two requests cannot both acquire it. It is held while a run is fetching, diffing, applying or rolling back and released when the run reaches `previewed` or a terminal state, so a preview waiting for review blocks nothing. A scheduled fetch that finds the lock held, or finds an earlier scheduled run still unfinished, logs the reason and returns instead of queueing behind it. A lock held for 15 minutes with no row progress can be released from the admin.

## 6. One bad row never fails the batch

Diff, apply and rollback each wrap their per-row work in `try`/`catch ( \Throwable )` and record the row as `failed`, `apply_failed` or `rollback_failed` with a reason (`wc_error`, `product_missing`, and so on) before moving on. The diff batch originally lacked this: a product whose read threw (a corrupt product or another plugin's hook) aborted the batch, and because Action Scheduler does not retry by default the run was stranded in `diffing` with no pending action and no way to resume. Apply also re-checks the lock flag and reloads the product at write time, so a product locked or deleted between preview and apply is skipped or recorded as failed rather than written.

## 7. Rollback restores snapshots written once per run and product

Before the first write to a product, apply stores its prior stock, regular price and sale price in `wss_snapshots` with `INSERT IGNORE` against a unique `(run_id, product_id)` key, so a resumed batch can never overwrite the true prior value. Only the most recent applied run can be rolled back. A product that manages stock but has no quantity reads back as `null`, which is indistinguishable from a product not managing stock, so the snapshot also records `manages_stock`. Restoring that state passes `''` to `set_stock_quantity()`, because passing `null` is coerced to 0 by `wc_stock_amount()` and would have left the run's value or a false zero in place. Snapshots written without the flag fall back to the older non-null check.

## 8. Feeds are hostile input and are validated per row at staging

CSV is read with `fgetcsv` one row at a time; remote feeds are downloaded with `wp_safe_remote_get( ..., array( 'stream' => true ) )` to a temp file first. The safe variant refuses loopback and private-network hosts, ports other than 80, 443 and 8080, and redirects to any of them; the first version used `wp_remote_get()`, which let anyone with `manage_woocommerce` make the server request internal addresses through the feed URL. A supplier feed that genuinely lives on a private host can be allowed with core's `http_request_host_is_external` filter. JSON has no streaming parser in core, so a JSON feed is decoded whole under the 20 MB cap. Each row is mapped and validated before staging: a missing SKU, a value that is not a non-negative number (money formats such as `$9.99` or `1,5` are rejected as `invalid_number`, not truncated), or a sale price not below the regular price fails that row only. When an SKU repeats, the first row wins and later ones are `duplicate_sku`. A blank cell means "no change", except that a blank sale price clears the sale when the `blank_clears_sale` setting is on. If a mapped column is missing from a CSV header (a supplier renamed it), the run fails with the missing column names instead of silently staging every row as no change; JSON objects are legitimately sparse, so that check is CSV only. The seen-SKU set used for duplicate detection grows with row count, so memory is constant for the read but linear for the run, bounded by the row cap.

## 9. Price markup is applied once, at staging

An optional percentage or fixed markup (`wss_adjust_price()`) is applied to the regular and sale prices while rows are staged, never to stock, and the result is rounded to the store's price decimals and clamped at zero. The adjusted value is stored in `wss_rows.new_values`, so the diff shows it, the change decision compares against it and apply writes the same number. Applying the markup separately at diff and apply time would let a settings change between preview and apply make the preview untrue. The sale-below-regular check runs on the adjusted values, rollback is unaffected because snapshots hold the real prior values, and a zero or `none` markup returns the input unchanged.

## 10. Locking a product excludes it, and locking a parent excludes its variations

The "Lock from stock sync" checkbox on the product inventory panel saves `_wss_locked = yes`. `WSS_Runner::is_locked()` checks the product and its parent, so a locked variable product protects all of its variations. Locked rows are recorded as `skipped` / `locked` in both the diff and the apply-time re-check.

## 11. The feed credential and the server's paths stay out of view

The optional auth header value is stored in `wss_settings`, rendered masked, and kept when the field is submitted blank; only an explicit clear removes it. A kept value is only reused for the host it was saved with (`wss_same_feed_host()`): saving the settings with a feed URL on a different host and a blank value is rejected until the value is entered again or cleared, and the column-listing request drops the stored value for a different host. Without that, a write-only secret could be read back by pointing the feed at a server one controls. It never appears in `source_ref`, logs or CLI output. Header names are validated against RFC 7230 token characters, feed URLs must be http or https, and uploads are limited to `.csv` and `.json` by extension and MIME, size-capped, and stored as `feed-<random>.<ext>` in `uploads/wss-feeds/` behind an `.htaccess` deny and an `index.php`. `source_ref` keeps the absolute upload path so a run's fetch is reproducible, but `wss_format_source()` shows only the basename. Every admin action requires `manage_woocommerce` and its own nonce.

## 12. WP-CLI drives the same runner, with fixed-English output and real exit codes

`wp wss fetch`, `dry-run`, `apply`, `rollback` and `runs` call the same `WSS_Runner` methods as the admin, synchronously; the Action Scheduler batches they also enqueue become no-ops because their status guards no longer match. Run-level failures (unknown run, wrong state, lock held, nothing to roll back) exit 1 through `WP_CLI::error()`, while row-level failures are reported in the counts with exit 0. CLI output is untranslated because it is an operator and script interface; the admin UI is fully translatable.

## 13. Tests run in two modes, and integration needs MariaDB

`tests/bootstrap.php` runs in stub mode when no WordPress test library is available, executing the pure parsing, validation and lock tests, and in integration mode against real WordPress, WooCommerce and Action Scheduler when `WP_TESTS_DIR` is set. CI runs both so the no-database path keeps working for contributors. The database must be MariaDB: `WP_UnitTestCase` turns the plugin's `CREATE TABLE` into `CREATE TEMPORARY TABLE`, and the uninstall test checks the tables with `SHOW TABLES`, which lists temporary tables on MariaDB but not on MySQL. CI pins WordPress 6.8.2 with WooCommerce 10.6.2, the newest WooCommerce that still supports WordPress 6.8, so an upstream release cannot turn CI red on its own. Because `Test_Lock` is a plain PHPUnit case outside the WordPress transaction, it force-releases the lock in `setUp` and `tearDown`; otherwise one test's lock leaked into the next.
