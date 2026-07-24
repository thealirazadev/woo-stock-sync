# Contributing to Woo Stock Sync

Thanks for your interest in improving Woo Stock Sync. This plugin keeps WooCommerce stock levels and
prices in sync with a supplier feed, with a dry-run diff, batched apply, and rollback. It is a
WordPress plugin written to the WordPress Coding Standards, so most of the workflow will be familiar
if you have contributed to WordPress or WooCommerce before.

By participating you agree to abide by our [Code of Conduct](CODE_OF_CONDUCT.md).

## Ways to contribute

- Report a bug or request a feature through the issue templates.
- Improve the documentation in `docs/` or the `README.md`.
- Fix a bug or implement an accepted feature with a pull request.

If you plan to work on something non-trivial, please open an issue first so we can agree on the
approach before you invest time. Product behaviour is defined in `docs/PRD.md` and
`docs/architecture.md`; those are the source of truth and are not changed casually.

## Development setup

You need PHP 7.4+ (the CI matrix runs PHP 8.2) and Composer.

```bash
git clone https://github.com/thealirazadev/woo-stock-sync.git
cd woo-stock-sync
composer install
```

That installs the dev tooling: PHP_CodeSniffer with the WordPress Coding Standards, PHPUnit, and the
PHPUnit polyfills.

## Coding standards

- All PHP follows the **WordPress Coding Standards** enforced by `phpcs.xml.dist` (WordPress-Extra,
  WordPress-Docs, and PHPCompatibilityWP). Lint must be clean before a change is merged.
- Prefix everything: functions/hooks `wss_`, classes `WSS_` (files `class-wss-*.php`), constants
  `WSS_`. The text domain is `woo-stock-sync`.
- All product reads and writes go through WooCommerce CRUD (`wc_get_product`, setters, `save()`),
  never direct SQL or `update_post_meta`. The plugin's own audit tables are accessed only through
  `$wpdb` with `$wpdb->prepare` or the format-array methods.
- Background work runs through Action Scheduler; no raw WP-Cron.
- Every user-facing string is wrapped in a translation function against the `woo-stock-sync` text
  domain. Escape all output at the point of echo.
- No JS build step: plain vanilla JavaScript and CSS only.
- The full engineering rulebook lives in [`docs/rules.md`](docs/rules.md); please read it before your
  first change.

Run the linter, and let it auto-fix what it can:

```bash
composer run lint        # phpcs
composer run lint:fix    # phpcbf (fixes the auto-fixable violations)
```

## Tests

The suite has two modes and picks one automatically in `tests/bootstrap.php`:

- **Stub mode** runs the pure-logic tests (feed parsing/validation and the run lock) with no
  database. This is what a bare `composer run test` does and what every contributor can run:

  ```bash
  composer run test
  ```

- **Integration mode** boots WordPress, WooCommerce, and Action Scheduler and runs the whole suite
  against real products in a test database. It needs a WordPress core checkout, the WordPress core
  test library, and a MySQL/MariaDB server. The exact provisioning commands (pinned to WordPress
  6.8.2 and WooCommerce 10.6.2, the same pair CI uses) are in
  [`docs/testing.md`](docs/testing.md). In short:

  ```bash
  export WP_TESTS_DIR=/tmp/wordpress-tests-lib
  export WC_PLUGIN_PATH=/tmp/woocommerce/woocommerce
  composer run test
  ```

Add or update tests for any behaviour you change. New product-writing logic needs an integration
test; pure parsing/validation logic needs a unit test. A change is not done until `composer run lint`
and `composer run test` both pass.

## WP-CLI

Every admin action is also available from WP-CLI, and the commands are handy for exercising the
pipeline by hand on a real site:

```bash
wp wss fetch [--source=<url>]        # create a run, fetch, stage, and diff
wp wss dry-run [--run=<id>] [--status=<status>] [--format=table|json]
wp wss apply --run=<id> [--yes]      # apply (or resume) a previewed run
wp wss rollback [--yes]              # roll back the most recent applied run
wp wss runs [--format=table|json]    # list run history
```

If you touch the CLI, keep its output in fixed English (it is a machine/operator interface, per
`docs/api-contracts.md`) while the admin UI stays fully translatable.

Regenerate the translation template after adding or changing any admin string:

```bash
wp i18n make-pot . languages/woo-stock-sync.pot
```

## Commits

- Use [Conventional Commits](https://www.conventionalcommits.org/): `feat`, `fix`, `chore`, `docs`,
  `refactor`, `test`, `perf`, with a short imperative subject (e.g. `fix: handle empty cart`).
- **One discrete change per commit** (a migration, a model, a route, a parser branch, a test) and
  keep every commit in a working state. Do not bundle a whole feature into one commit.
- No emoji, and no authorship or tooling mentions anywhere in commits, comments, or docs.
- Any schema change is a new numbered `dbDelta` migration step in `WSS_Install`, guarded by the
  `wss_db_version` option. Applied migration steps are never edited afterward.
- Pin exact dependency versions in `composer.json` and commit `composer.lock`. Adding a dependency
  needs prior agreement.

## Pull requests

Before opening a PR:

1. `composer run lint` is clean.
2. `composer run test` passes (run the integration suite too if your change touches the pipeline).
3. New behaviour is covered by tests.
4. User-facing strings are translated and the `.pot` is regenerated if you added any.
5. The PR description explains the what and the why, and links the issue it addresses.

The pull request template will walk you through this checklist. CI runs phpcs, `php -l` over every
non-vendor PHP file, and both test modes on every push; a PR needs a green run to merge.

## License

By contributing you agree that your contributions are licensed under the GPL-2.0-or-later license
that covers this project.
