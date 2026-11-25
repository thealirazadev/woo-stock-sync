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
WP_DEBUG on. Fixture feed for manual runs: `tests/fixtures/sample-feed.csv` (valid rows, an
unknown SKU, a non-numeric price, a sale >= regular row, a duplicate SKU, and a row for a product
to be locked).

Manual checklist:

- Activate with WooCommerce off: notice shown, no fatal. With it on, the three `wss_` tables exist.
- Save settings with no SKU column mapped: field error, nothing saved.
- Upload the fixture CSV, map columns, fetch: the run reaches previewed and the diff shows the
  expected planned, no_change, unknown_sku and failed rows. The same file served over HTTP with an
  auth header gives an identical diff; a 404 or 401 fails the run with a friendly message.
- Apply the fixture run: valid rows update, unknown-SKU, invalid and locked rows are untouched, and
  the summary counts match the diff. Locking a variable parent skips its variations too.
- Stop the worker mid-apply: the run screen shows Resume after the stall window, and resuming
  completes the run with no double stock write.
- Start a second sync while one is applying: refused. Release lock clears a stale lock.
- Roll back the applied run: prior stock and prices are restored; a product deleted first is
  rollback_failed / product_missing while the rest restore. Older applied runs offer no rollback.
- Hourly schedule: exactly one recurring action in Tools > Scheduled Actions; running it creates a
  run that stops at previewed, or applies when auto_apply is on; with the lock held it skips.
- Progress bar advances during a large apply and polling stops at the terminal status. Cancelling a
  previewed run removes the Apply button.
- CLI only: fetch, dry-run, apply --yes, rollback --yes, with runs visible in the admin history.
  `wp wss dry-run --format=json` parses; `apply` while the lock is held and `rollback` with nothing
  applied exit 1.
- Price markup: +15% turns a 20.00 feed price into 23.00 in the diff and on apply; +5.00 fixed
  turns 10.00 into 15.00; none or 0 shows the raw price; a markdown below zero writes 0.00; rollback
  restores the prior prices.
- Keyboard-only walk of Settings, runs list, run detail, and the confirmations, with visible focus
  and announced progress. Uninstall removes tables, options, meta, uploaded feeds, and scheduled
  actions.

## Exact commands

Run all from the plugin root.

- Install dev dependencies (first time): `composer install`
- First-time test-suite setup (once per environment): see "Provisioning the WordPress test
  environment" below
- Run the full test suite: `composer run test`
- Run a single test file: `vendor/bin/phpunit tests/test-apply.php`
- Lint: `composer run lint`
- Auto-fix fixable lint issues: `composer run lint:fix`
- Regenerate translation template: `wp i18n make-pot . languages/woo-stock-sync.pot`
- Build the distributable zip: `composer run build`

`composer.json` scripts map as: `test` -> `phpunit`, `lint` -> `phpcs`, `lint:fix` -> `phpcbf`,
`build` -> `wp dist-archive`.

## Provisioning the WordPress test environment

The suite has two modes and picks one automatically in `tests/bootstrap.php`:

- **Stub mode** (no `WP_TESTS_DIR`, or nothing installed there): only the pure-logic tests run;
  everything extending `WSS_Integration_TestCase` self-skips. This is what a bare `composer run
  test` does.
- **Integration mode**: WordPress, WooCommerce, Action Scheduler and this plugin are loaded for
  real and the whole suite executes.

Integration mode needs three things: a WordPress core checkout, the WordPress core test library,
and a MySQL/MariaDB server. `wp-env` provisions all three, but it is not required - and where
`wp-env` cannot start (no working Docker Compose, for example) the suite still runs, because
nothing about it depends on wp-env. Provision the pieces directly:

```bash
WP_VERSION=6.8.2
WC_VERSION=10.6.2
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
export WC_PLUGIN_PATH=/tmp/woocommerce/woocommerce

# 1. A database. Any MySQL/MariaDB reachable over TCP will do, for example:
docker run -d --name wss-test-db -e MARIADB_ROOT_PASSWORD=password -p 3306:3306 mariadb:11.4
docker exec wss-test-db mariadb -uroot -ppassword -e 'CREATE DATABASE wordpress_test'

# 2. WordPress core (what ABSPATH points at).
curl -sSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" -o /tmp/wordpress.tar.gz
mkdir -p /tmp/wordpress && tar --strip-components=1 -xzf /tmp/wordpress.tar.gz -C /tmp/wordpress

# 3. The core test library (includes/ + data/ + a config), from wordpress-develop.
curl -sSL "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${WP_VERSION}.tar.gz" \
  -o /tmp/wordpress-develop.tar.gz
tar -xzf /tmp/wordpress-develop.tar.gz -C /tmp
mkdir -p "$WP_TESTS_DIR"
cp -r "/tmp/wordpress-develop-${WP_VERSION}/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
cp -r "/tmp/wordpress-develop-${WP_VERSION}/tests/phpunit/data" "$WP_TESTS_DIR/data"
cp "/tmp/wordpress-develop-${WP_VERSION}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s#dirname( __FILE__ ) . '/src/'#'/tmp/wordpress/'#; s#__DIR__ . '/src/'#'/tmp/wordpress/'#" \
  "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/youremptytestdbnamehere/wordpress_test/; s/yourusernamehere/root/; \
  s/yourpasswordhere/password/; s/localhost/127.0.0.1/" "$WP_TESTS_DIR/wp-tests-config.php"

# 4. WooCommerce (which bundles Action Scheduler).
curl -sSL "https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip" -o /tmp/woocommerce.zip
unzip -q /tmp/woocommerce.zip -d /tmp/woocommerce

# 5. Run everything.
composer run test
```

`bin/install-wp-tests.sh` does steps 2, 3 and the database from Subversion instead, if `svn` and a
`mysqladmin` client are available; the commands above need neither.

Notes:

- `WC_PLUGIN_PATH` points at the WooCommerce directory. Without it the bootstrap expects
  WooCommerce next to this plugin, which is the layout inside `wp-content/plugins`.
- The pinned pair is WordPress 6.8.2 with WooCommerce 10.6.2 (the newest WooCommerce that still
  declares support for WordPress 6.8). CI pins the same pair so a local failure is reproducible.
- MariaDB, not MySQL: `WP_UnitTestCase` creates the plugin's tables as `TEMPORARY` tables, and
  `SHOW TABLES` lists those on MariaDB but not on MySQL, which the uninstall test relies on.
  wp-env uses MariaDB too, so this matches the usual WordPress setup.
- The database is dropped and recreated by the test suite on each run; never point it at real data.

## Continuous integration

`.github/workflows/ci.yml` runs on every push and pull request to `main` with two jobs, both on
PHP 8.2:

- **Lint and test**: `composer install`, `composer run lint` (phpcs), `php -l` over every PHP file
  outside `vendor/`, and `composer run test` in stub mode (no WordPress, so integration tests
  self-skip).
- **Integration tests**: a `mariadb:11.4` service, then the same provisioning steps as above for
  WordPress 6.8.2, its core test library, and WooCommerce 10.6.2, followed by `composer run test`
  in integration mode.

`composer run build` is not part of CI because it needs WP-CLI and the dist-archive package.

## Definition of "done" gate

A change is not done until:

1. `composer run lint` is clean.
2. `composer run test` passes, in integration mode if the change touches the pipeline.
3. The relevant manual checklist items above pass.
4. The browser console and PHP debug log show no new warnings or notices on the touched screens.
