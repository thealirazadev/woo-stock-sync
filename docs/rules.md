# Engineering Rules: woo-stock-sync

These rules are binding for every change to this plugin. When something here conflicts with a quick
shortcut, follow these rules.

## Conventions

- Coding standard: WordPress Coding Standards (WPCS). Run PHPCS before every commit; code must be
  clean against `phpcs.xml.dist`.
- Preferred libraries/patterns:
  - WordPress/WooCommerce core APIs: `wp_remote_get`, `wp_enqueue_script`/`wp_enqueue_style`,
    `wp_nonce_field`/`check_admin_referer`, `current_user_can`, the `sanitize_*`/`esc_*` families,
    `wp_send_json_success`/`wp_send_json_error`, `WP_List_Table`, `__()`/`esc_html__()`.
  - All product reads/writes through WooCommerce CRUD: `wc_get_product`,
    `wc_get_product_id_by_sku`, setters + `save()`. Never write product data with SQL or
    `update_post_meta`.
  - All background work through Action Scheduler (`as_enqueue_async_action`,
    `as_schedule_recurring_action`, `as_has_scheduled_action`). No raw WP-Cron events.
  - Plugin tables are accessed only via `$wpdb` with `$wpdb->prepare` (or `$wpdb->insert` /
    `$wpdb->update` with format arrays). No string-built SQL.
  - CSV parsing streams with `fgetcsv`; never `file_get_contents` a feed file.
- What to avoid:
  - No JS framework or build step (no React, Vue, webpack). Plain JS and CSS only.
  - No direct `$_POST`/`$_GET`/`$_FILES` access without unslash + sanitize.
  - No output of unescaped data; every echo of dynamic content goes through `esc_html`, `esc_attr`,
    `esc_url`, or `wp_kses_post`.
  - No global functions, hooks, or actions without the `wss_` prefix.
  - No long-running work in an admin request; anything touching more than one product runs in an
    Action Scheduler action or WP-CLI.
- Naming:
  - Text domain: `woo-stock-sync` (matches slug and folder).
  - Function/hook prefix: `wss_` (e.g. `wss_get_run()`, filter `wss_batch_size`, actions
    `wss_fetch_run`, `wss_diff_batch`, `wss_apply_batch`, `wss_rollback_batch`).
  - Constant prefix: `WSS_` (`WSS_VERSION`, `WSS_PATH`, `WSS_URL`).
  - Class prefix: `WSS_` PascalCase (`WSS_Runner`), file `class-wss-runner.php`.
  - Tables: `{$wpdb->prefix}wss_runs`, `{$wpdb->prefix}wss_rows`, `{$wpdb->prefix}wss_snapshots`.
  - Options: `wss_settings`, `wss_db_version`, `wss_active_run`. Product meta: `_wss_locked`.
  - Nonces: one per action, field `<action>_nonce`, e.g. action `wss_apply_run`, field
    `wss_apply_run_nonce`.
  - Files lowercase-hyphenated; class files prefixed `class-`; variables/functions `snake_case`.
  - Row status and reason values are the exact lowercase snake_case enums listed in
    `docs/architecture.md`; never invent ad-hoc strings.
- Commits: Conventional Commits (`feat`, `fix`, `chore`, `docs`, `refactor`, `test`, `perf`) with a
  short imperative subject (e.g. `feat: stage feed rows with per-row validation`).
- ONE COMMIT PER FEATURE / TASK. Never batch multiple features into one commit. Each commit listed
  in `docs/phases.md` maps to exactly one commit.
- Pin exact dependency versions in `composer.json` (no `^`/`~` ranges), commit `composer.lock`,
  and declare `Requires at least`, `Requires PHP`, and `WC requires at least` in the plugin header
  and `readme.txt`. No blanket upgrades without approval.
- Database migration rule: never modify the schema directly. Every schema change is a new numbered
  migration step in `WSS_Install`, guarded by the `wss_db_version` option, applied idempotently via
  dbDelta on `plugins_loaded` (admin/CLI only). Applied migration steps are never edited afterward;
  fixes ship as a new step.

## Error handling & logging

- Every boundary call handles failure: `wp_remote_get` (check `is_wp_error` and HTTP status),
  `fopen`/`fgetcsv` (false returns), `json_decode` (null + `json_last_error`), every `$wpdb` write
  (false return), `wc_get_product` (false/null), `WC_Product::save()` (wrapped in try/catch —
  `WC_Data_Exception` and hooks from other plugins can throw). A row-level failure is recorded on
  the row and never aborts the batch; a run-level failure sets the run `failed` with `error` filled
  and releases the lock.
- Friendly user errors vs detailed logs: admin screens and CLI show plain-language messages
  ("Feed URL returned HTTP 401. Check the auth header in Settings."); full detail (URL, row
  number, exception message) goes to the log and the row `message` column. Never show stack
  traces, SQL, or file paths to users; `WP_DEBUG_DISPLAY` off in production.
- One consistent error format everywhere:
  - Ajax: `wp_send_json_error( array( 'code' => ..., 'message' => ... ) )`.
  - admin-post: redirect back with `wss_notice` + `wss_notice_type` query args rendered as an
    admin notice.
  - CLI: `WP_CLI::error( $message )` (exit 1).
  - Rows: `status` + `reason` enum + human `message`. The same reason enums are used in admin,
    CLI, and tests.
- Structured logging from day one: a single helper `wss_log( $message, $context = array() )`
  wrapping `WC_Logger` (`wc_get_logger()->log( $level, ... , array( 'source' => 'woo-stock-sync' ) )`),
  with the context array JSON-encoded. All logging goes through this helper; it is added in Phase 1
  and called at every failure branch and at run start/finish with run ID and counts.

## Security

- No hardcoded secrets. The only secret is the feed auth header value: entered in Settings, stored
  in the `wss_settings` option, rendered masked (never re-echoed in full), excluded from
  `source_ref`, logs, and CLI output. The plugin reads no `.env`.
- Capability: every admin screen, admin-post handler, ajax handler, and the lock checkbox save
  require `manage_woocommerce`. WP-CLI runs as an administrative context by definition.
- Nonces: every state-changing request verifies its own nonce (`wss_save_settings`,
  `wss_start_fetch`, `wss_apply_run`, `wss_cancel_run`, `wss_rollback_run`, `wss_release_lock`,
  `wss_run_progress`, `wss_feed_columns`) via `check_admin_referer` / `check_ajax_referer`.
- Validate all input server-side: settings (source type against enum, URL via `esc_url_raw` +
  http/https scheme check, header name against RFC token characters, schedule against enum,
  mapping columns against the actual feed header list); feed values (numeric check before cast,
  non-negative, sale < regular); uploads (extension and MIME limited to csv/json, size cap,
  stored under `wp-content/uploads/wss-feeds/` with a random filename, `.htaccess` deny and
  `index.php`); run IDs cast to int and verified to exist and be in a legal state for the action.
- SQL injection: plugin tables only via `$wpdb->prepare` / format-array methods. Product data only
  via WooCommerce CRUD (parameterized by core).
- XSS: all run/row data rendered in admin (SKUs, messages, feed-derived values) is escaped with
  `esc_html`/`esc_attr` at output. Feed content is hostile input.
- SSRF note: feed URLs accept http/https only. Fetches go through `wp_remote_get` (honoring
  `WP_HTTP_BLOCK_EXTERNAL`/`WP_ACCESSIBLE_HOSTS` if the site sets them); no redirect following
  beyond the WP default, response size capped.
- Protected actions summary: everything under the Stock Sync menu plus all wss_* admin-post/ajax
  actions require `manage_woocommerce` + a valid action nonce. Editing `_wss_locked` requires
  `edit_post` on that product (saved through the normal product save flow). There are no
  unauthenticated or shopper-facing surfaces.

## Simplicity (YAGNI / KISS)

- Write the minimum code that satisfies the current phase. Prefer core helpers over new code.
- Rule of three: no abstraction until the same logic exists three times. The batch loop is written
  once in `WSS_Runner` and parameterized for diff/apply/rollback only if it stays readable;
  otherwise three plain methods are fine.
- No new wrapper/factory/manager/utils class beyond the classes named in `docs/architecture.md`
  without approval recorded in `docs/memory.md`.
- No config options beyond those in `wss_settings` listed in the architecture doc. Batch size, row
  cap, and JSON size cap are constants with a filter, not settings.
- Self-review before submitting: "Can this be done in fewer lines without hurting readability?"
  If yes, rewrite first. Pause and justify any single function approaching ~150 lines.
- Use existing machinery: Action Scheduler for queues, WP_List_Table for tables, WC_Logger for
  logs, dbDelta for schema. Do not hand-roll any of these.

## Code style

- Sparse, human comments that explain "why", not "what". No commented-out code.
- Concise WPCS-style DocBlocks with `@param`/`@return` where relevant. No boilerplate prose.
- No emoji anywhere in code, comments, docs, or commits.
- No AI/authorship mentions anywhere: no "generated by", no co-author trailers, no tool names in
  comments, commits, or docs.
- Conventional Commits, short imperative subjects.

## Boundaries

- No wholesale file delete or rewrite. Targeted, reviewable edits; flag destructive changes first.
- Never change `docs/PRD.md` or `docs/architecture.md` without flagging it first and recording the
  reason in `docs/memory.md`.
- No new runtime or dev dependency without approval recorded in `docs/memory.md`. Action Scheduler
  is used from the WooCommerce bundle, not required via Composer.
- Ask when ambiguous instead of assuming product behavior.
- Stop after 2 failed fix attempts on the same problem and explain what was tried.
- Mid-phase requests not in `docs/PRD.md`: ask whether to (a) add to the current phase, (b) create
  a new phase, or (c) log to the Backlog in `docs/phases.md`. Never silently absorb scope.
