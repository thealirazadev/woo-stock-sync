# Phases: woo-stock-sync

Rule: phase N+1 does not start until the owner approves phase N (definition of done, manual
checklist, and verification all pass). One commit per feature/task, in the listed order. The senior
differentiators — partial-failure isolation, dry-run-then-apply with rollback, and shared-hosting
batch processing with resumability and overlap protection — are hard requirements of Phases 1 and 2.

---

## Phase 1: Foundation, schema, feed ingest, and dry-run diff

Goal: a store owner can configure a feed, fetch it, and see a complete dry-run diff of what a sync
would change — with every bad row isolated and reported — before any product is touched. No writes
to products in this phase.

### Definition of done
- Plugin activates only when WooCommerce is active; otherwise a clear admin notice, no fatal.
- `wss_runs`, `wss_rows`, and `wss_snapshots` tables are created through `WSS_Install` versioned
  dbDelta migrations keyed by `wss_db_version`; re-activation and repeat loads are idempotent.
- Settings screen saves source type (upload/url), URL, auth header name + masked value, and the
  column mapping (SKU required, at least one of stock/regular price/sale price); invalid settings
  are rejected with field-level errors. "Load columns" reads the feed header over admin-ajax.
- Uploaded CSVs are validated (extension, MIME, size) and stored in a protected uploads subdir.
- "Fetch and preview" creates a run and schedules `wss_fetch_run`; the CSV path streams via
  `fgetcsv` (constant memory), JSON decodes under the 20 MB cap, and rows are staged into
  `wss_rows` with per-row validation. Invalid rows (missing SKU, non-numeric value, sale >=
  regular, duplicate SKU) are recorded `failed` with the correct reason and never abort the parse.
- `wss_diff_batch` resolves SKUs, fills `current_values`, and sets each row to `planned`,
  `no_change`, `unknown_sku`, or `stock_not_managed` (stock field only); the run reaches
  `previewed` with accurate counts.
- Runs list and run detail screens render; the diff table shows product, field, current value, new
  value, and action per row, paginated and filterable by status. All feed-derived output escaped.
- A remote fetch failure (401, 404, timeout, wrong content) sets the run `failed` with a friendly
  run-level error and a detailed `wss_log` entry; no partial staging is left behind.
- `wss_log` exists (wrapping WC_Logger, source `woo-stock-sync`) and is used at every failure
  branch and at run start/finish.

### Manual test checklist
- Activate with WooCommerce off: notice shown, no fatal. Activate with it on: tables exist
  (check with `wp db query "SHOW TABLES LIKE '%wss_%'"`).
- Save settings with no SKU column mapped: field error, nothing saved.
- Upload the fixture CSV, map columns, fetch: run appears, reaches previewed, diff shows expected
  planned/no_change/unknown_sku/failed rows with correct current and new values.
- Point the URL source at the same file served over HTTP with an auth header: identical diff.
- Break the URL (404) and the header (401): run failed with a friendly message, log has detail.
- Feed with a duplicate SKU: first row planned, second recorded failed/duplicate_sku.

### Verification
- Run the app: walk Settings and both run screens on a WooCommerce site; check the browser console
  and PHP debug log for warnings/notices.
- Run `composer run test` and `composer run lint`: both clean.
- Unhappy paths: malformed CSV line mid-file (row failed, parse continues); empty feed file (run
  previewed with zero rows, empty-state message); no network during remote fetch (run failed,
  friendly error); double-click "Fetch and preview" (second fetch refused while a run is still
  fetching or diffing — a simple active-run status check in this phase; the atomic lock lands in
  Phase 2); refresh the run screen mid-diff (progress state renders, no duplicate scheduling).
- Empty states: no runs yet (runs list empty state); diff with zero changes ("Nothing to change").
- Long inputs: 100-char SKU and very long feed values render without breaking the table layout.

### Commits
- `chore: scaffold plugin bootstrap, header, and constants`
- `feat: block activation when WooCommerce is inactive with admin notice`
- `chore: add structured wss_log helper wrapping WC_Logger`
- `feat: create runs, rows, and snapshots tables with versioned migrations`
- `feat: add settings screen with feed source and auth header`
- `feat: add column mapping with feed header loader`
- `feat: download remote feed to temp file with auth header`
- `feat: stream-parse csv and stage rows with per-row validation`
- `feat: decode json feeds under documented size cap`
- `feat: compute dry-run diff in scheduled batches`
- `feat: add runs list and run detail diff screens`
- `test: cover parsing validation and diff statuses`

---

## Phase 2: Batched apply, locking, and rollback

Goal: applying a previewed run writes products in resumable Action Scheduler batches with per-row
failure isolation and overlap protection, honors locked products, snapshots prior values, and the
last applied run can be rolled back.

### Definition of done
- Apply is available only on a `previewed` run; it acquires the `wss_active_run` lock atomically
  (`add_option`) and refuses to start (clear notice) if the lock is held.
- `wss_apply_batch` processes 25 rows per action (filter `wss_batch_size`); per row it re-checks
  the lock flag, upserts the prior values into `wss_snapshots` (insert-once per run + product),
  writes via WooCommerce CRUD inside try/catch, and marks `applied` or `apply_failed` with reason.
  A throwing row never aborts its batch; the run finishes `applied` with counts matching row
  statuses exactly.
- Batch actions carry only the run ID; the cursor is derived from remaining `planned` rows, so a
  batch killed mid-way resumes with no row applied twice and no row lost. The run screen detects a
  stall (no row progress, no pending action) and offers Resume.
- "Lock from stock sync" checkbox on the product inventory panel (and variations) saves
  `_wss_locked`; locked products (and variations of locked parents) are recorded skipped/locked at
  diff and re-checked at apply.
- Rollback is offered only on the most recent applied run; `wss_rollback_batch` restores
  snapshotted values via CRUD, marks snapshots restored and rows `rolled_back` /
  `rollback_failed` (`product_missing`, `wc_error`), and ends with status `rolled_back`.
- The lock is released on finish, failure, and rollback completion; a "Release lock" admin action
  clears a stale lock (15+ minutes without row progress) behind a nonce + capability check.

### Manual test checklist
- Apply the Phase 1 fixture run: valid rows update (verify stock and prices on the products),
  unknown-SKU/invalid/locked rows untouched, summary counts match the diff.
- Lock a product, re-fetch, apply: its row is skipped/locked, values unchanged. Lock a variable
  parent: variation rows skipped too.
- Kill PHP mid-apply (stop the worker), reload the run screen: stalled state with Resume; resume
  completes the run; spot-check no product got a double stock write.
- Start a second fetch-and-apply while one is applying: refused with a notice. Release lock action
  clears a manufactured stale lock.
- Roll back the applied run: prior stock and prices restored exactly; run shows rolled_back with
  per-row results. Delete one applied product first: that row is rollback_failed/product_missing,
  the rest restore.
- Rollback is not offered on an older applied run once a newer one has been applied.

### Verification
- Run the app: full fetch -> preview -> apply -> rollback cycle on Storefront with WP_DEBUG on;
  console and debug log clean.
- Run `composer run test` and `composer run lint`: both clean (apply isolation, resume idempotency,
  lock, snapshot-once, and rollback tests green).
- Unhappy paths: product save that throws (simulate via a test hook) records apply_failed/wc_error
  and continues; apply clicked twice quickly (second refused by lock and run-state check); refresh
  the run screen mid-apply (progress renders, no duplicate batches); rollback with WooCommerce
  deactivated mid-way (run failed cleanly, lock released).
- Empty states: applying a run with zero planned rows completes immediately as applied with zero
  counts; rollback with zero snapshots refused with a friendly message.
- Long inputs: a product with a very long name renders in results rows without breaking layout.

### Commits
- `feat: add atomic sync lock with acquire and release`
- `feat: apply planned rows in action scheduler batches`
- `feat: snapshot prior values once per product before write`
- `feat: isolate row failures with recorded reasons during apply`
- `feat: add lock from stock sync checkbox on products`
- `feat: skip locked products and variations in diff and apply`
- `feat: resume stalled applies from database cursor`
- `feat: roll back last applied run from snapshots`
- `feat: add release lock action for stale locks`
- `test: cover apply isolation, resume, lock, and rollback`

---

## Phase 3: Scheduled sync, progress, and cancel

Goal: hands-off recurring fetches with an explicit auto-apply opt-in, live progress on run screens,
and the ability to cancel a previewed run.

### Definition of done
- Saving a schedule (hourly, twicedaily, daily, manual) registers/updates/removes exactly one
  Action Scheduler recurring `wss_scheduled_fetch` action; changing the interval reschedules it.
- Scheduled runs are created with trigger_type `schedule`, stop at `previewed` in preview_only
  mode, and continue into apply when auto_apply is enabled.
- A scheduled fetch that finds the lock held, or the previous scheduled run still unfinished,
  skips with a `wss_log` reason and does not stack.
- Run screens poll `wss_run_progress` (admin-ajax, nonce + capability) every few seconds during
  fetching/diffing/applying/rolling_back and render counts and a progress bar; polling stops on
  terminal states.
- A `previewed` run can be cancelled (status `cancelled`); cancelled runs cannot be applied.

### Manual test checklist
- Set hourly schedule: exactly one recurring action visible in Tools > Scheduled Actions; run it
  manually from there: a scheduled run appears and stops at previewed.
- Enable auto_apply, run the scheduled action: run applies without admin interaction.
- Hold the lock (mid-apply) and trigger the scheduled action: skipped, reason logged, no new run.
- Watch a large apply: progress bar advances, counts update, polling stops at applied.
- Cancel a previewed run: status cancelled, Apply button gone.

### Verification
- Run the app: schedule flows and progress on both run screens; console and debug log clean.
- Run `composer run test` and `composer run lint`: both clean.
- Unhappy paths: interval changed while a scheduled action is pending (old action replaced, not
  duplicated); ajax progress call with a bad nonce (error JSON, no data leak); cancel submitted
  twice (second is a friendly no-op); browser left on the run screen after completion (polling
  stopped, no console errors).
- Empty states: schedule set to manual (no recurring action remains); progress poll on a finished
  run returns terminal state once.
- Long inputs: progress and counts render correctly for a 10,000-row run.

### Commits
- `feat: schedule recurring feed fetch at configured interval`
- `feat: add scheduled mode setting with auto apply opt-in`
- `feat: skip scheduled fetch when lock or unfinished run exists`
- `feat: poll run progress and render progress bar`
- `feat: cancel previewed runs`
- `test: cover schedule registration, skip, and cancel`

---

## Phase 4: WP-CLI commands

Goal: every admin action is scriptable: fetch, dry-run, apply, rollback, and history from the
command line, against the same tables and runner code.

### Definition of done
- `wp wss fetch` creates a run, fetches, stages, and diffs synchronously, prints the run ID and
  summary counts; `--source=<url>` overrides the configured URL for that run.
- `wp wss dry-run [--run=<id>]` prints the diff rows (product, field, current, new, action) for
  the given or latest previewed run; `--status=<status>` filters; `--format=table|json`.
- `wp wss apply --run=<id> [--yes]` applies (or resumes) a previewed/stalled run synchronously in
  the same batch loop, honoring the lock; prints per-reason counts; `--yes` skips confirmation.
- `wp wss rollback [--yes]` rolls back the last applied run with the same rules as admin.
- `wp wss runs [--format=table|json]` lists run history with status and counts.
- All commands exit 0 on success and 1 on failure with a one-line friendly error; row-level
  failures do not cause a non-zero exit (they are reported in counts), run-level failures do.
- Command signatures, outputs, and exit codes match `docs/api-contracts.md` exactly.

### Manual test checklist
- Full cycle from CLI only: fetch, dry-run, apply --yes, verify a product changed, rollback --yes,
  verify restored. Runs also visible in the admin history.
- `wp wss apply --run=<previewed-id>` while the admin holds the lock: exit 1, friendly message.
- `wp wss dry-run --format=json | jq .` parses; `--status=unknown_sku` filters correctly.
- `wp wss rollback` when nothing is applied: exit 1 with a clear message.

### Verification
- Run the app: CLI cycle against a real WooCommerce site; admin screens reflect CLI-created runs.
- Run `composer run test` and `composer run lint`: both clean.
- Unhappy paths: invalid `--run` ID (exit 1); fetch with unreachable URL (exit 1, run failed);
  apply interrupted with Ctrl+C then re-run (resumes, no double-apply); malformed `--format`
  value (WP-CLI validation error).
- Empty states: `wp wss runs` with no history prints an empty table, exit 0.
- Long inputs: JSON output for a large run streams without exhausting memory.

### Commits
- `feat: add wss fetch and dry-run cli commands`
- `feat: add wss apply and rollback cli commands`
- `feat: add wss runs cli command with format option`
- `test: cover cli exit codes and output`

---

## Phase 5: i18n, uninstall, and hardening

Goal: translation-ready strings, clean uninstall, accessibility and escaping pass, green quality
gates.

### Definition of done
- All user-facing strings wrapped in translation functions with the `woo-stock-sync` text domain;
  `languages/woo-stock-sync.pot` generated.
- `uninstall.php` drops the three `wss_*` tables, deletes `wss_settings`, `wss_db_version`,
  `wss_active_run`, all `_wss_locked` meta, uploaded feed files, and unschedules all wss actions.
- Accessibility pass on admin screens: labels on all inputs, keyboard-operable tables and buttons,
  visible focus, `role="status"` on the progress region, WCAG 2.1 AA contrast on status badges.
- Escaping/sanitization audit of every template and handler; PHPCS clean; PHPUnit green.
- `readme.txt` and plugin headers declare accurate WP/WC/PHP minimums.

### Manual test checklist
- Switch site language with a test translation: admin strings translate.
- Uninstall: tables, options, meta, uploaded feeds, and scheduled actions all gone; reinstall
  recreates schema cleanly at the current version.
- Keyboard-only walk of Settings, runs list, run detail, and apply/rollback confirmations; focus
  visible everywhere; screen reader announces progress updates.
- Run PHPCS and PHPUnit: both pass.

### Verification
- Run the app: final full cycle (fetch, preview, apply, rollback, schedule) on a clean install
  with WP_DEBUG on; console and debug log clean.
- Run `composer run test` and `composer run lint`: both clean.
- Unhappy paths: uninstall while a run is mid-apply (actions unscheduled, no orphan lock);
  activation on a site where the tables already exist (migrations no-op cleanly).
- Empty states: fresh install shows correct empty states on every screen.
- Long inputs: translated strings with long words do not break button or badge layout.

### Commits
- `refactor: wrap all user-facing strings in text domain`
- `chore: generate translation template pot file`
- `fix: complete accessibility and escaping audit of admin screens`
- `chore: declare wp, wc, and php minimums in headers and readme`
- `feat: drop plugin tables, options, and jobs on uninstall`
- `test: cover uninstall cleanup`

---

## Backlog

(Empty. Add out-of-scope or deferred items here as they arise.)
