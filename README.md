# woo-stock-sync

A WooCommerce plugin that keeps store stock levels and prices in sync with a supplier feed. A store
owner points the plugin at a CSV upload or a remote CSV/JSON URL, maps feed columns to product
fields keyed by SKU, previews every change as a dry-run diff, applies the sync in background batches
through Action Scheduler, and can roll back the last applied sync from stored prior values. Every
run, row result, and rollback is recorded in custom audit tables, and the same actions are available
from WP-CLI.

Status: implemented (v1.0.0)

## Stack

- PHP 7.4+ WordPress plugin (procedural bootstrap + small single-responsibility classes).
- WordPress 6.0+ and WooCommerce 7.0+; product writes go through WooCommerce CRUD APIs only.
- Action Scheduler (bundled with WooCommerce) for scheduled fetches and batched apply/rollback.
- Custom database tables (versioned dbDelta migrations) for sync runs, row results, and snapshots.
- Vanilla JavaScript and plain CSS for the admin screens; no build step.
- Dev tooling: Composer, PHP_CodeSniffer with WordPress Coding Standards, PHPUnit with the
  WordPress test suite.

## Install

1. Copy the plugin folder to `wp-content/plugins/woo-stock-sync` (or install the built zip through
   the Plugins screen). WooCommerce must be active first.
2. Activate it, then open WooCommerce > Stock Sync > Settings to configure a feed source and mapping.

For development:

```
composer install
```

## Run

Use WooCommerce > Stock Sync in the admin, or the WP-CLI commands:

```
wp wss fetch [--source=<url>]        # create a run, fetch, stage, and diff
wp wss dry-run [--run=<id>] [--status=<status>] [--format=table|json]
wp wss apply --run=<id> [--yes]      # apply (or resume) a previewed run
wp wss rollback [--yes]              # roll back the most recent applied run
wp wss runs [--format=table|json]    # list run history
```

## Test

```
composer run lint                    # PHP_CodeSniffer (WordPress standard)
composer run test                    # PHPUnit
```

Pure-logic tests (feed parsing/validation, the sync lock) run standalone. Integration tests need the
WordPress test suite plus WooCommerce; install it once with
`bin/install-wp-tests.sh wordpress_test root '' localhost latest` (or run the suite under
`wp-env`), and they self-skip otherwise.

## Build

```
composer run build                   # wp dist-archive -> build/woo-stock-sync.zip
```

## Documentation

Planning docs live in `docs/`: `PRD.md` (requirements), `architecture.md` (design and data model),
`rules.md` (engineering rules), `phases.md` (build plan), `design.md` (admin UI), `testing.md`
(test strategy), `api-contracts.md` (admin-ajax and WP-CLI contracts), `memory.md` (work log),
`launch-checklist.md` (release gate).
