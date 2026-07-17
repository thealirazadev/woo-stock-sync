# Memory: woo-stock-sync

Discipline: update this file after every meaningful chunk of work. Log every non-obvious decision
in the Decisions log with its reason.

## Completed

- Repo initialized (git identity Ali Raza / thealirazadev), tooling installed (PHPCS + WPCS 3.1.0,
  PHPUnit 9.6.19, PHPCompatibilityWP), scaffold committed.

## In progress

- Phase 2: batched apply, locking, rollback.

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
