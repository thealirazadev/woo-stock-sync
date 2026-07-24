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

- 2026-07-22 - The integration suite was executed for the first time, against real WordPress 6.8.2 +
  WooCommerce 10.6.2 + Action Scheduler. Before: 29 tests collected, 14 executed, 15 skipped (59
  assertions). After: 29 executed, 0 skipped, 113 assertions, green and repeatable. Two findings:
  1. `tests/test-diff.php::test_a_throwing_product_is_isolated_and_the_preview_completes` (written
     with the diff-isolation fix but never run) was verified both ways: with the fix it passes; with
     the fix reverted the `RuntimeException` escapes `handle_diff_batch()` and the test errors, which
     is exactly the stranded-run defect. The fix is adequate — the row lands `failed`/`wc_error`,
     the cursor advances, and `finalize_preview` counts it in `rows_failed`.
  2. `Test_Lock` failed under the WP suite. Broken test, not a code bug: it is a plain PHPUnit case,
     so it is outside the `WP_UnitTestCase` transaction, and resetting the stub option array no
     longer clears a real option — the first test left run 1 holding the lock. Fixed by
     force-releasing the lock in `setUp`/`tearDown`.
  `tests/bootstrap.php` now honours `WC_PLUGIN_PATH` so WooCommerce can live anywhere; CI gained an
  `integration-tests` job and `docs/testing.md` documents the provisioning commands.

## In progress

- None. All five phases complete.

## 2026-07-25 - Repo-maturity and accessibility pass

Genuine follow-up hardening. Before: 38 tests / 156 assertions. After: 39 tests / 158 assertions,
green in integration mode (WordPress 6.8.2 + WooCommerce 10.6.2) and stub mode. phpcs and php -l clean.

Repo-maturity files the public repo lacked, each its own commit and each excluded from the plugin zip
via `.distignore` where it is a top-level dev/doc file (the `.github/` templates are already covered by
the existing `/.github` entry):

1. `CONTRIBUTING.md` - project-specific: the two-mode test setup from `docs/testing.md`, phpcs/phpunit
   commands, WP-CLI usage, the make-pot step, and PR/commit expectations.
2. `CODE_OF_CONDUCT.md` - Contributor Covenant 2.1 verbatim; enforcement contact routed through the
   same GitHub private reporting channel `SECURITY.md` already uses (no dedicated conduct inbox exists).
3. `.github/ISSUE_TEMPLATE/` - bug_report.md, feature_request.md, config.yml. config disables blank
   issues and links the Security advisory page and the WordPress.org support forum.
4. `.github/PULL_REQUEST_TEMPLATE.md` - checklist tied to phpcs + phpunit, CRUD-only writes, dbDelta
   migrations, i18n/pot, and one-change-per-commit.
5. `.editorconfig` - matches the real style: tabs for PHP/JS/CSS (WPCS), 2-space YAML, 4-space JSON
   (composer.json), untrimmed markdown/readme.
6. `CHANGELOG.md` - Keep a Changelog format, single honest 1.0.0 entry. Dated 2026-07-23 (the
   feature-complete date from this log; no git release tag exists to date it precisely, and no version
   was invented). readme.txt remains the WordPress.org changelog.

Code improvements (Tier 2), gap verified before each:

7. i18n: the committed `.pot` was stale (generated 2026-07-18, before the 2026-07-23 hardening pass).
   It was missing the wrapped `__()` string "The feed is missing these mapped columns: %s...".
   Regenerated with the documented `wp i18n make-pot .`; the only content change is that one string
   plus refreshed line references.
8. test: `Test_Schedule::test_scheduled_fetch_skips_when_a_prior_scheduled_run_is_unfinished`. The
   `has_unfinished_scheduled_run()` guard in `handle_scheduled_fetch()` was documented behavior with no
   test (only the lock-held skip was covered). The test configures valid URL settings first so
   `begin_run()` would otherwise create a run - isolating the guard as the only thing that stops it -
   then asserts no new scheduled run while a prior `previewed` run is unfinished, and that a new run
   does start once that run is terminal. Verified failing with the guard disabled (count went 1 -> 2).
9. a11y (settings): field validation errors are now tied to their controls. The three text/url/file
   inputs add `aria-invalid="true"` alongside the existing `aria-describedby`; the required SKU mapping
   select, which previously had no error association at all, gains `aria-invalid` + `aria-describedby`
   pointing at its error. Kept to the template's phpcs-safe literal-ternary idiom.
10. a11y (run detail): the progress bar was `aria-hidden` (AT saw no progress). It is now a proper
    `role="progressbar"` with `aria-valuemin/valuemax/valuenow` and an `aria-label` ("Sync progress"),
    with `aria-valuenow`/`aria-valuemax` updated by the poller in `admin.js`. `.pot` regenerated for the
    new label string. The outer `role="status"` live region still announces the readable text.

Status badges were audited and deliberately left unchanged: colour is decorative and the status word is
always printed (documented in `assets/css/admin.css`), so they already convey status to AT; adding aria
would be redundant. WP_List_Table already emits `scope="col"` column headers. No schema change, no new
dependency, all product writes still via WooCommerce CRUD.

## 2026-07-23 - Edge-case hardening pass

Genuine follow-up work after the integration suite came online. Before: 29 tests / 113 assertions
(integration mode). After: 38 tests / 156 assertions, green, and 38 / 19-skipped green in stub mode.

1. Managed-but-null rollback (the review edge). A product managing stock with no quantity set reads
   back null; `snapshot_product` recorded null, and `rollback_snapshot` guarded on
   `null !== $prior['stock_quantity']`, so it skipped the restore and left the run's applied value in
   place. Decision: restore to the managed-but-unset (null) state. Two facts drove the fix. (a) null
   is ambiguous in the snapshot - it means either "managed but unset" or "not managing stock" - so the
   snapshot now records a `manages_stock` flag (inside the `prior_values` JSON, no schema change) and
   rollback keys off it (old snapshots fall back to the prior non-null guard). (b) WooCommerce's
   `set_stock_quantity( null )` coerces to 0 via `wc_stock_amount()` (`'' !== null` -> `intval(null)`),
   so restoring the null state passes `''`, which `set_prop`s null. Covered by
   `Test_Rollback::test_rollback_restores_a_managed_but_unset_stock_quantity` (verified failing first:
   stock stayed at 50, then null after the fix).
2. Missing mapped column (CSV). A saved mapping can drift from a feed (a supplier renames a column);
   the CSV path treated the absent column as a blank cell and staged every row as no-change with no
   signal. `parse_csv` now fails the run naming the missing columns. JSON deliberately left as is - its
   objects are legitimately sparse, so the first object's keys are not an authoritative column list.
3. Feed-format edges: money-formatted values (currency symbol, thousands separator, trailing code,
   space grouping) are rejected as `invalid_number` not truncated; duplicate SKU keeps the first row;
   malformed JSON fails the run; a non-object JSON element fails only its own row.
4. Apply-time isolation for two more reasons: a product deleted between preview and apply lands
   `apply_failed`/`wc_error` and the batch continues; a product locked after preview is `skipped`/
   `locked` at the apply-time re-check, values untouched.
5. WP-CLI: `apply` on a run with row-level failures exits 0 (no `WP_CLI::error`) and reports the
   failures in its counts, complementing the existing run-level exit-1 cases.

All product writes stayed on WooCommerce CRUD. No schema change (the `manages_stock` flag lives in the
existing snapshot JSON). No new dependencies. `phpcs`, `php -l`, and both suite modes clean.

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
- 2026-07-22 - SUPERSEDED: the integration tests do run on a hosted runner, and the claim that they
  need wp-env was wrong. They need three things wp-env happens to bundle — a WordPress core
  checkout, the core test library from `wordpress-develop`, and a MySQL/MariaDB server — and all
  three are a few `curl` calls plus a service container. `ci.yml` now has an `integration-tests` job
  with a `mariadb:11.4` service that provisions them and runs the full suite; `lint-and-test` keeps
  running the suite in stub mode so the no-database path stays covered. The provisioning avoids
  `bin/install-wp-tests.sh` because that script needs `svn` and a `mysqladmin` client, neither of
  which is guaranteed on the runner image; tarballs need neither.
- 2026-07-22 - WordPress 6.8.2 + WooCommerce 10.6.2 are pinned in CI (and documented for local runs).
  10.6.2 is the newest WooCommerce that still declares "Requires at least: 6.8"; 10.8+ requires
  WordPress 6.9. Pinning both keeps a WooCommerce release from turning CI red on its own schedule.
- 2026-07-22 - The test database must be MariaDB, not MySQL. `WP_UnitTestCase` rewrites the plugin's
  `CREATE TABLE` into `CREATE TEMPORARY TABLE`, and `Test_Uninstall` proves the tables exist and are
  then dropped via `SHOW TABLES LIKE`. MariaDB lists temporary tables in `SHOW TABLES`; MySQL does
  not, so that test would fail there. wp-env also runs MariaDB, so this is the ordinary setup, but
  it is a real constraint and not an accident.
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
