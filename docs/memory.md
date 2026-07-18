# Memory: woo-stock-sync

Discipline: update this file after every meaningful chunk of work. Log every non-obvious decision
in the Decisions log with its reason.

## Completed

- Repo initialized (git identity Ali Raza / thealirazadev), tooling installed (PHPCS + WPCS 3.1.0,
  PHPUnit 9.6.19, PHPCompatibilityWP), scaffold committed.

## In progress

- None. All five phases complete.

## Phase 4 (complete)

- WP-CLI `wp wss` commands: fetch (with `--source` override), dry-run (`--run`/`--status`/`--format`),
  apply (`--run`/`--yes`), rollback (`--yes`), runs (`--format`). Commands drive the same runner
  methods synchronously (batch guards make the leftover Action Scheduler enqueues no-ops); run-level
  failures exit 1 via WP_CLI::error, row-level failures are reported in counts (exit 0). Verification:
  `phpcs` clean; CLI callbacks tested with WP_CLI stubs (integration, skip under stubs).

## Phase 5 (complete)

- All user-facing admin strings wrapped in the `woo-stock-sync` text domain (verified by make-pot,
  169 strings; CLI output stays fixed English per docs/api-contracts.md). `languages/woo-stock-sync.pot`
  regenerated. Accessibility: settings error summary receives focus after a failed save; progress
  region is role=status/aria-live; confirmations disable their button on submit. `WSS_Install::uninstall()`
  (called by uninstall.php) drops the three tables, deletes the options and `_wss_locked` meta, removes
  the uploads subdir via WP_Filesystem, and unschedules all wss actions. Verification: `phpcs` clean;
  `phpunit` 27 tests / 57 assertions (13 pure-logic run live, 14 integration skip without the WP suite).

## Phase 3 (complete)

- Recurring `wss_scheduled_fetch` registered/rescheduled/removed on settings save (exactly one action
  via `as_unschedule_all_actions` then `as_schedule_recurring_action`); schedule + scheduled_mode
  settings; scheduled runs auto-apply when opted in (from `finalize_preview`); scheduled fetch skips
  (logged) when the lock is held or a prior scheduled run is unfinished; live progress via the
  `wss_run_progress` ajax poll + progress bar (reduced-motion aware) that reloads on terminal status;
  cancel a previewed run. Verification: `phpcs` clean; `phpunit` 22 tests (9 integration skip under
  stubs).

## Phase 2 (complete)

- Atomic single-run lock via `add_option( 'wss_active_run', ... )` (acquire on fetch/apply/rollback,
  release at previewed/terminal); batched apply through Action Scheduler with per-row try/catch
  isolation (wc_error), snapshot-once per product before write, resume from the DB cursor (stall
  detection at 10 min idle + no pending action), roll back the latest applied run from snapshots
  (product_missing handled), per-product/variation "Lock from stock sync" checkbox skipped in diff
  and apply, and a stale-lock release action (15 min idle). Verification: `phpcs` clean; `phpunit`
  19 tests (lock mutex verified live; apply/diff/rollback integration tests skip without the WP
  suite).

## Phase 1 (complete)

- Bootstrap + WooCommerce-active guard; schema (runs/rows/snapshots) via versioned dbDelta with
  `maybe_upgrade` on load; settings screen (feed source, masked auth header, upload with protected
  dir, column mapping) with server-side validation and PRG feedback; ajax "Load columns"; remote
  download (streamed) + source resolution + CSV streaming parser + JSON decode (20 MB cap) + per-row
  validation (missing/duplicate SKU, invalid number, sale>=regular); background fetch/stage +
  batched dry-run diff (planned/no_change/unknown_sku/stock_not_managed); runs list + run detail diff
  screens; status badges. Verification: `phpcs` clean on all files; `phpunit` 12 tests / 48
  assertions pass (2 diff integration tests skip without the WP suite).

## Decisions log

- 2026-07-18 - Verification runs statically (php -l + PHPCS WordPress standard) plus PHPUnit with
  WP+Woo stubs for pure-logic tests; wp-env used for live/integration verification was attempted
  twice and both attempts failed at "Reading configuration" with ETIMEDOUT (github.com flaky, IPv6
  broken in this sandbox) before any Docker work. Fell back per docs handoff. Integration tests are
  written against the WP test suite and will run under wp-env on a networked host; they self-skip
  when WooCommerce is not loaded so the suite stays green under stubs.
- 2026-07-18 - .wp-env.json `core` set to null (latest wordpress.org release) instead of the
  WordPress/WordPress git repo, to avoid the flaky github.com clone path.
- 2026-07-18 - Processing caps (row cap 50k, JSON 20 MB, batch size 25) are constants with filters
  at point of use, per docs/rules.md, not settings.
- 2026-07-18 - `source_ref` stores the URL for remote runs and the absolute upload path for upload
  runs (so a fetch is deterministic), but the UI only ever shows the basename for uploads
  (`wss_format_source`) to avoid leaking server paths per docs/rules.md.
- 2026-07-18 - Staging inserts rows in chunks of 250 via one prepared multi-row INSERT to keep a
  50k-row fetch inside a single Action Scheduler action's time budget.
- 2026-07-18 - Phase 1 overlap guard is a run-status check (`active_run_id`); the atomic
  `wss_active_run` option lock lands in Phase 2 as documented.
- 2026-07-18 - Granular commits adopted mid-run per owner instruction: each discrete change (a
  helper, an admin action, a table, a parser branch, a test) is its own working commit.
