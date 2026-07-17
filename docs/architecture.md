# Architecture: woo-stock-sync

## App flow and architecture

A self-contained WordPress plugin. There is no external service; the "backend" is WordPress itself,
reached through admin screens (admin-post + admin-ajax), Action Scheduler background jobs, and
WP-CLI. One main plugin file bootstraps everything.

1. Bootstrap (`woo-stock-sync.php`): defines constants, checks WooCommerce is active, loads the
   text domain, requires `includes/` files, runs schema migrations via `WSS_Install`, and
   instantiates the orchestrator `WSS_Plugin` on `plugins_loaded`. Registers WP-CLI commands when
   `WP_CLI` is defined.
2. Admin (`WSS_Admin`, `WSS_Settings`): a "Stock Sync" submenu under WooCommerce with two screens,
   Runs and Settings. All state-changing actions go through `admin-post.php` handlers with nonce +
   capability checks; the run-progress poll and the "load feed columns" helper go through
   `admin-ajax.php`. `WSS_Admin` also adds the "Lock from stock sync" checkbox to the product
   inventory panel and saves it to `_wss_locked`.
3. Pipeline (`WSS_Feed`, `WSS_Runner`): every sync is a run row in `wss_runs` that moves through a
   fixed lifecycle. Fetch/parse/stage and diff/apply/rollback execute inside Action Scheduler
   actions, never inside an admin request. WP-CLI drives the same `WSS_Runner` methods
   synchronously.

### Run lifecycle

```
pending -> fetching -> diffing -> previewed -> applying -> applied
                                     |             |
                                 cancelled     (resume on stall)
any stage -> failed                            applied -> rolling_back -> rolled_back
```

1. Fetch and stage (`wss_fetch_run` async action): resolve the source (uploaded file path, or
   remote URL downloaded to a temp file via `wp_remote_get` with `stream => true` and the
   configured auth header). Stream-parse CSV with `fgetcsv` (JSON: decode whole file under a
   documented 20 MB size cap), apply the mapping snapshot, validate each row's values
   (numeric, non-negative; sale < regular when both present), and insert one `wss_rows` row per
   feed row. Invalid rows are recorded with status `failed` + reason (`invalid_number`,
   `invalid_sale_price`, `missing_sku`, `duplicate_sku` — first occurrence of a SKU wins) and
   never abort the parse. Hard cap: 50,000 rows per run.
2. Diff (`wss_diff_batch` recurring-until-done action): in batches, resolve SKU to product ID via
   `wc_get_product_id_by_sku`, load current values through WooCommerce CRUD, and set each row to
   `planned` (with `current_values` filled), `no_change`, `locked`, `unknown_sku`, or
   `stock_not_managed` (stock field only; price fields on the same row still apply). When no
   staged rows remain the run becomes `previewed` and counts are totalled.
3. Apply (`wss_apply_batch` action, only from `previewed`): acquire the sync lock, then per batch
   select the next N rows `WHERE run_id = X AND status = 'planned' ORDER BY id LIMIT N`. Per row:
   re-check the lock flag, upsert the product's prior values into `wss_snapshots` (unique per
   run + product, never overwritten so retries keep the true prior value), write new values via
   WooCommerce CRUD (`set_stock_quantity`, `set_regular_price`, `set_sale_price`, `save()`), and
   mark the row `applied` or `apply_failed` + reason (`wc_error`, `locked`). A thrown row error is
   caught, recorded, and the batch continues. When no `planned` rows remain, finalize counts, set
   `applied`, release the lock, and schedule the next batch otherwise.
4. Rollback (`wss_rollback_batch` action, latest applied run only): batches over `wss_snapshots`
   for the run, restore prior values via WooCommerce CRUD, mark snapshots restored and rows
   `rolled_back` / `rollback_failed` (reason `product_missing`, `wc_error`). Uses the same lock.

### Batching, resumability, overlap protection

- Batch size: 25 rows per Action Scheduler action (filter `wss_batch_size`). Reasoning: each row
  costs one product load + one CRUD save (roughly 10-20 queries plus third-party hooks); 25 rows
  completes in a few seconds, safely inside Action Scheduler's ~30-second time budget and typical
  shared-hosting `max_execution_time` of 30s, while keeping queue overhead low (a 10,000-row apply
  is 400 actions).
- Resumability: batch actions carry only `run_id`. The cursor is derived from the database each
  time (next rows still in `planned` status), and every row reaches a terminal status the moment
  it is processed. If a batch dies mid-way, re-scheduling one batch for the run resumes exactly
  where it stopped; nothing is applied twice because processed rows are no longer `planned`, and
  snapshots are insert-once. The admin run screen detects a stall (status `applying`, no row
  updated for 10+ minutes, no pending action for the run) and offers Resume; `wp wss apply
  --run=<id>` does the same.
- Overlap protection: a single mutex via `add_option( 'wss_active_run', $run_id, '', false )`,
  which is atomic thanks to the unique key on `option_name`. Every active stage requires the lock:
  it is acquired when a run starts fetching, applying, or rolling back, and released when the run
  reaches `previewed` or a terminal state — so a previewed run awaiting review does not block
  anything, but no two runs can ever be doing work concurrently. Scheduled fetches skip with a
  logged reason when the lock is held. The lock is deleted on finish/failure; the admin shows a
  "Release lock" action for stale locks (held longer than 15 minutes with no row progress).
  Because diffs reflect fetch time, the apply confirmation always shows the run's age.

## Proposed folder / file tree

```
woo-stock-sync/
  woo-stock-sync.php                Plugin header, constants (WSS_VERSION, WSS_PATH, WSS_URL),
                                    WooCommerce-active guard, text domain, requires, boot WSS_Plugin
  uninstall.php                     Drops wss_* tables, deletes wss_* options and _wss_locked meta
  readme.txt                        WordPress.org-style plugin readme
  composer.json                     Dev deps + scripts (lint, lint:fix, test, build)
  phpcs.xml.dist                    PHPCS ruleset extending WordPress-Extra + WordPress-Docs
  phpunit.xml.dist                  PHPUnit config
  .distignore                       Files excluded from the built zip
  bin/install-wp-tests.sh           WP test-suite installer (wp scaffold plugin-tests)

  includes/
    class-wss-plugin.php            Singleton orchestrator; instantiates the classes below
    class-wss-install.php           dbDelta schema, versioned migrations keyed by wss_db_version
    class-wss-settings.php          Settings screen render + sanitize + save (wss_settings option)
    class-wss-admin.php             Menu, runs list/detail screens, admin-post + ajax handlers,
                                    product lock checkbox, admin notices
    class-wss-runs-table.php        WP_List_Table for the runs list
    class-wss-rows-table.php        WP_List_Table for run detail rows (status filter, pagination)
    class-wss-feed.php              Source resolution, remote download, CSV streaming, JSON decode,
                                    header/column listing, row validation + mapping
    class-wss-runner.php            Run lifecycle: create, stage, diff, apply, rollback, cancel,
                                    resume; Action Scheduler callbacks; lock acquire/release
    class-wss-cli.php               WP_CLI command class (wss fetch|dry-run|apply|rollback|runs)
    wss-functions.php               wss_log(), wss_get_run(), wss_get_settings(), small helpers

  templates/
    admin/settings.php              Settings screen markup
    admin/run-detail.php            Run summary header + diff table wrapper + action buttons

  assets/
    css/admin.css                   Status badges, diff table, progress bar
    js/admin.js                     Progress polling, load-columns helper, confirm dialogs

  languages/
    woo-stock-sync.pot              Translation template

  tests/
    bootstrap.php                   Loads WP test suite + WooCommerce + plugin
    fixtures/sample-feed.csv        Small fixture feed (valid, unknown-SKU, invalid, locked rows)
    test-feed-parsing.php           Streaming parse, mapping, validation, caps
    test-diff.php                   Diff statuses incl. unknown_sku, no_change, locked
    test-apply.php                  Batch apply, isolation, snapshots, resume idempotency
    test-rollback.php               Snapshot restore, product_missing handling
    test-lock.php                   Overlap protection and stale-lock release
    test-cli.php                    CLI command wiring and exit codes

  docs/                             Planning + handoff documentation (this folder)
  README.md                         Root project doc
```

## Tech stack with rationale

- WordPress plugin in PHP: the requirement is a WooCommerce extension; a plugin integrating through
  documented hooks is the only correct delivery form. PHP 7.4+ to match WooCommerce's floor.
- Action Scheduler for scheduling and batching: it ships inside WooCommerce (no new dependency),
  persists jobs in the database, survives shared hosting where WP-Cron alone is unreliable, retries
  failed actions, and gives an admin debug UI (Tools > Scheduled Actions) for free. WP-Cron is used
  by nothing directly; the recurring fetch is an Action Scheduler recurring action.
- Custom tables instead of post meta for runs, rows, and snapshots: a 10,000-row feed synced daily
  would write millions of meta rows with no efficient way to list runs, page row results, filter by
  status, or delete old runs. Runs and row results are not attributes of any post, and history must
  be queryable (counts by status, latest applied run) and cleanable. Three narrow InnoDB tables
  with proper indexes are the boring, correct fit. The only post meta used is `_wss_locked`, which
  genuinely is a product attribute.
- WooCommerce CRUD for all product writes: `wc_get_product` / setters / `save()` keep caches,
  lookup tables (`wc_product_meta_lookup`), stock status transitions, and third-party hooks
  correct. Direct SQL writes to products are forbidden.
- Streaming CSV via `fgetcsv` and `wp_remote_get( ..., array( 'stream' => true ) )`: constant
  memory regardless of feed size. JSON has no streaming parser in core, so JSON feeds are decoded
  whole under a 20 MB cap — a documented trade-off; suppliers with bigger feeds use CSV.
- Vanilla JS + plain CSS in admin: the UI is forms, tables, and a polled progress bar; a framework
  or build step would add risk without benefit. WP_List_Table gives native pagination/filtering.
- Composer + PHPCS/WPCS + PHPUnit: the conventional quality gates for a distributable plugin.
  Exact versions are pinned at install time and `composer.lock` is committed.

## Data model

Custom tables (created by dbDelta, prefixed with `$wpdb->prefix`):

`wss_runs` — one row per sync run.

| Column | Type | Notes |
| --- | --- | --- |
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | run ID |
| status | VARCHAR(20) | pending, fetching, diffing, previewed, applying, applied, rolling_back, rolled_back, cancelled, failed |
| trigger_type | VARCHAR(10) | manual, schedule, cli |
| source_type | VARCHAR(10) | upload, url |
| source_ref | TEXT | file path or URL (auth header value never stored here) |
| mapping | LONGTEXT | JSON snapshot of the column mapping at run creation |
| rows_total / rows_planned / rows_skipped / rows_failed / rows_applied | INT UNSIGNED | summary counts |
| error | TEXT | run-level failure message |
| created_by | BIGINT UNSIGNED | user ID; 0 for schedule/CLI |
| created_at / finished_at | DATETIME | UTC |

Indexes: PK, KEY `status`, KEY `created_at`.

`wss_rows` — one row per feed row per run.

| Column | Type | Notes |
| --- | --- | --- |
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | processing cursor (ordered) |
| run_id | BIGINT UNSIGNED | FK to wss_runs |
| row_num | INT UNSIGNED | position in the feed file |
| sku | VARCHAR(100) | raw SKU from feed |
| product_id | BIGINT UNSIGNED NULL | resolved product/variation ID |
| new_values | LONGTEXT | JSON, e.g. {"stock":"12","regular_price":"9.99","sale_price":""} |
| current_values | LONGTEXT NULL | JSON, filled at diff time |
| status | VARCHAR(20) | staged, planned, no_change, skipped, failed, applied, apply_failed, rolled_back, rollback_failed |
| reason | VARCHAR(30) NULL | unknown_sku, invalid_number, invalid_sale_price, missing_sku, locked, stock_not_managed, wc_error, product_missing, duplicate_sku |
| message | TEXT NULL | detail for logs/screen |
| updated_at | DATETIME | last status change |

Indexes: PK, KEY `run_status` (`run_id`, `status`, `id`), KEY `run_row` (`run_id`, `row_num`).

`wss_snapshots` — prior values captured at apply time; the rollback source of truth.

| Column | Type | Notes |
| --- | --- | --- |
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| run_id | BIGINT UNSIGNED | FK to wss_runs |
| product_id | BIGINT UNSIGNED | product or variation ID |
| prior_values | LONGTEXT | JSON of stock_quantity, regular_price, sale_price before apply |
| restored | TINYINT(1) | 0/1 |

Indexes: PK, UNIQUE KEY `run_product` (`run_id`, `product_id`).

Relationships: one run has many rows and many snapshots; a row optionally references one product; a
snapshot references exactly one product per run. Products are core WooCommerce entities and are
never written outside the CRUD API. Duplicate SKUs within one feed: the first occurrence wins,
later occurrences are recorded `failed` / `duplicate_sku`.

Options and meta:

| Key | Where | Purpose |
| --- | --- | --- |
| `wss_settings` | option | source type, url, auth header name + value, schedule interval, scheduled mode (preview_only or auto_apply), mapping, blank-sale-clears flag |
| `wss_db_version` | option | applied schema version for migrations |
| `wss_active_run` | option | mutex holding the active run ID |
| `_wss_locked` | product meta | 'yes' when the product is locked from sync |

## Where state lives

- Persistent state: the three `wss_*` tables (runs, rows, snapshots), the `wss_settings` option,
  and `_wss_locked` product meta. Uploaded feed files live in
  `wp-content/uploads/wss-feeds/` (random filename, `.htaccess` deny + `index.php`).
- Job state: Action Scheduler's own tables hold pending/failed actions; our run status plus row
  statuses are the authoritative progress record (the cursor is always derived from `wss_rows`).
- Request-scoped: settings form values during save; validated and sanitized in
  `WSS_Settings::save()` before persisting.
- Client-side: none beyond the DOM. The progress bar polls `wss_run_progress` over admin-ajax; no
  cookies or local storage.

## External dependencies and required env vars

- Runtime: WooCommerce active (checked at bootstrap; activation blocked with an admin notice
  otherwise). Action Scheduler arrives bundled with WooCommerce.
- Remote feed: any HTTPS/HTTP endpoint returning CSV or a flat JSON array; one optional custom
  auth header. Feed URLs are restricted to http/https schemes.
- Secrets: the auth header value is entered on the settings screen, stored in the `wss_settings`
  option (same trust level as WooCommerce payment gateway keys), rendered masked, and never written
  to logs or run records. The plugin reads no environment variables and has no `.env`, so no
  `.env.example` ships. Local dev/QA uses `tests/fixtures/sample-feed.csv`, which needs no auth.
- Dev dependencies (Composer): `squizlabs/php_codesniffer`, `wp-coding-standards/wpcs`,
  `phpcompatibility/phpcompatibility-wp`, `phpunit/phpunit`, WordPress test-suite scaffolding.
