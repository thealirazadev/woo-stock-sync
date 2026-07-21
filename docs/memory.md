# Memory: woo-stock-sync

Discipline: update this file after every meaningful chunk of work. Log every non-obvious decision
in the Decisions log with its reason.

## Completed

- Repo initialized (git identity Ali Raza / thealirazadev), tooling installed (PHPCS + WPCS 3.1.0,
  PHPUnit 9.6.19, PHPCompatibilityWP), scaffold committed.
- 2026-07-22 - Root `LICENSE` (MIT, Copyright (c) 2026 Ali Raza) added, and
  `.github/workflows/ci.yml` added: on push/pull_request to main, PHP 8.2 on ubuntu-latest running
  `composer install`, `composer run lint` (phpcs), `php -l` over every non-vendor PHP file, and
  `composer run test` (phpunit). Verified against a clean `git archive` checkout before pushing.

- 2026-07-22 - Senior quality pass. Code review of the batch pipeline found one real defect: the
  diff batch had no per-row failure isolation, so a product whose WooCommerce read threw aborted the
  whole batch and (Action Scheduler does not retry a failed action by default) stranded the run in
  `diffing` with no pending action and no admin Resume path. Fixed by wrapping each row's diff in the
  same try/catch the apply and rollback batches already use, marking the row `failed` / `wc_error`
  so the cursor advances; covered by a new integration test in `tests/test-diff.php`. Also added
  README badges + a Design decisions section, standalone parser benchmark tooling in `bin/`,
  `SECURITY.md`, and a grouped monthly Composer `dependabot.yml`.

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
- 2026-07-22 - CI deliberately runs only the checks that pass on a stock hosted runner: no MySQL
  service, no `bin/install-wp-tests.sh`, no wp-env, so the WooCommerce integration tests self-skip
  there (14 of 27). `composer run build` is also out of CI because it needs WP-CLI plus the
  dist-archive package, which are not project dependencies. Full integration coverage stays a
  wp-env / WP-capable-host job, noted in a comment in the workflow.
- 2026-07-22 - Root LICENSE is MIT per owner instruction, while the plugin headers, `readme.txt`,
  and `composer.json` still declare GPL-2.0-or-later (required for WordPress.org distribution).
  Flagged rather than changed: those files are source of truth and were not in scope.
- 2026-07-22 - Superseded: the root LICENSE was switched to GPL-2.0 so it matches the plugin header,
  `readme.txt`, and `composer.json`. The repo is GPL-2.0-or-later throughout; the README license
  badge reflects that.
- 2026-07-22 - Parser benchmark measures `WSS_Feed::parse` with a no-op sink (parsing + mapping +
  validation only, no DB), under the existing pure-logic stubs, so the number is not diluted by
  product I/O. Peak memory grows with row count because duplicate-SKU detection keeps a seen-SKU set
  for the run; the 50k row cap bounds it to ~8.5 MB, so the streaming read stays constant-memory but
  the run as a whole is O(rows) in the dedup set. Recorded because `docs/architecture.md` describes
  the CSV path as "constant memory regardless of feed size", which is true of the read buffer only.
- 2026-07-22 - `bin/*` is excluded from phpcs: the benchmark scripts are standalone procedural PHP
  CLI tooling that is not shipped (already in `.distignore`), matching the existing `tests/support`
  exclusion.
- 2026-07-22 - Security dependency pass. One open Dependabot alert on the repo, confirmed against
  `composer audit`: `phpunit/phpunit` 9.6.19 -> 9.6.33 (high, GHSA-vvj3-c3rp-c85p / CVE-2026-24765,
  unsafe deserialization in PHPT code-coverage handling). Dev-only dependency, and the project runs
  no PHPT fixtures, so exposure was limited to a maliciously crafted test file already in the tree;
  bumped anyway. Patch-level bump inside 9.6, so no breakage: phpcs, `php -l` over all non-vendor
  PHP, and the 29-test suite all pass unchanged. `composer audit` now reports no advisories.
  No runtime dependencies exist (the plugin requires only PHP >= 7.4), so nothing shipped changed.
  `docs/architecture.md` names `phpunit/phpunit` but pins no version, so no doc update was needed.
  Dependabot PR #2 is superseded by this commit and can be closed.
