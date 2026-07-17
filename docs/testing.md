# Testing: woo-stock-sync

## Strategy

Three layers: unit-ish PHP tests for pure logic, integration tests against the WordPress +
WooCommerce test suite for the pipeline, and manual QA for admin UX and hosting-realistic failure
modes that automated tests cannot reproduce well.

### Unit-level (PHPUnit, no or minimal WP fixtures)

Pure logic that must not regress:

- Mapping and validation in `WSS_Feed`: column mapping application, numeric validation (rejects
  `abc`, `1,5`, negatives; accepts `0`, `9.99`), sale >= regular rejection, missing-SKU and
  duplicate-SKU detection, blank-cell semantics (blank = no change; blank sale price clears only
  when the setting is on).
- CSV streaming: fixture files with quoted fields, BOM, CRLF, a malformed line mid-file (row
  failed, parse continues), and header-only files. Assert memory stays flat on a generated
  10,000-row file (peak delta under a few MB).
- Settings sanitization in `WSS_Settings`: enum fallbacks, URL scheme rejection (ftp, javascript),
  header name token validation, mapping completeness rules.

### Integration (PHPUnit + WordPress test suite + WooCommerce)

The pipeline against real products created in the test DB:

- Diff: rows resolve to `planned`, `no_change`, `unknown_sku`, `locked`, `stock_not_managed`
  correctly for simple products and variations; counts on the run match row statuses.
- Apply isolation: a fixture feed with valid, unknown-SKU, invalid, and locked rows applies only
  the valid rows; each bad row carries the right reason; a product `save()` forced to throw (test
  hook) records `apply_failed`/`wc_error` and the batch continues.
- Snapshots and rollback: snapshot written once per run + product (retry does not overwrite);
  rollback restores exact prior stock/regular/sale values; deleted product yields
  `rollback_failed`/`product_missing` without aborting.
- Resumability: run one apply batch, assert partial terminal statuses, run the batch callback
  again, assert completion with no double-application (stock incremented exactly once).
- Lock: second acquire fails while held; released on finish and on run failure; stale release
  works.
- Schedule: saving intervals registers exactly one recurring action; changing/removing reschedules
  or unschedules; lock-held scheduled fetch skips.
- Migrations: fresh install creates tables at current `wss_db_version`; re-running is a no-op.
- Uninstall: tables, options, `_wss_locked` meta, and scheduled actions removed.
- CLI: command callbacks return correct exit codes and JSON output shapes (invoked directly, not
  via a subprocess).

### Static analysis / lint

- PHP_CodeSniffer with WordPress Coding Standards (`phpcs.xml.dist`) enforcing escaping,
  sanitization, prepared SQL, prefixing, and text-domain usage. Lint is part of the definition of
  done for every feature.

### Manual QA

What automation cannot cover: real Action Scheduler timing on a live site, killing a worker
mid-apply and resuming, admin UX (progress polling, badges, empty states, keyboard operation,
focus), remote fetch against a real HTTP server with auth, and behavior on Storefront with
WP_DEBUG on. The per-phase manual checklists and verification sections live in `docs/phases.md`.
Fixture feed for manual runs: `tests/fixtures/sample-feed.csv` (valid rows, an unknown SKU, a
non-numeric price, a sale >= regular row, a duplicate SKU, and a row for a product to be locked).

## Exact commands

Run all from the plugin root.

- Install dev dependencies (first time): `composer install`
- First-time test-suite setup (once per environment):
  `bin/install-wp-tests.sh wordpress_test root '' localhost latest` (WooCommerce is loaded by
  `tests/bootstrap.php`)
- Run the full test suite: `composer run test`
- Run a single test file: `vendor/bin/phpunit tests/test-apply.php`
- Lint: `composer run lint`
- Auto-fix fixable lint issues: `composer run lint:fix`
- Regenerate translation template: `wp i18n make-pot . languages/woo-stock-sync.pot`
- Build the distributable zip: `composer run build`

`composer.json` scripts map as: `test` -> `phpunit`, `lint` -> `phpcs`, `lint:fix` -> `phpcbf`,
`build` -> `wp dist-archive`.

## Definition of "done" gate

After creating or editing files, run build/tests and fix all errors before reporting done. A
feature is not done until:

1. `composer run lint` is clean.
2. `composer run test` passes.
3. The relevant manual checklist items in `docs/phases.md` pass.
4. The browser console and PHP debug log show no new warnings or notices on the touched screens.

Build and tests must pass before a feature is committed and before a phase is marked complete.
