# woo-stock-sync

A WooCommerce plugin that keeps store stock levels and prices in sync with a supplier feed. A store
owner points the plugin at a CSV upload or a remote CSV/JSON URL, maps feed columns to product
fields keyed by SKU, previews every change as a dry-run diff, applies the sync in background batches
through Action Scheduler, and can roll back the last applied sync from stored prior values. Every
run, row result, and rollback is recorded in custom audit tables, and the same actions are available
from WP-CLI.

Status: planning — docs under review

## Planned stack

- PHP 7.4+ WordPress plugin (procedural bootstrap + small single-responsibility classes).
- WordPress 6.0+ and WooCommerce 7.0+; product writes go through WooCommerce CRUD APIs only.
- Action Scheduler (bundled with WooCommerce) for scheduled fetches and batched apply/rollback.
- Custom database tables (versioned dbDelta migrations) for sync runs, row results, and snapshots.
- Vanilla JavaScript and plain CSS for the admin screens; no build step.
- Dev tooling: Composer, PHP_CodeSniffer with WordPress Coding Standards, PHPUnit with the
  WordPress test suite.

## Install

TBD until implementation starts.

## Run

TBD until implementation starts.

## Test

TBD until implementation starts.

## Documentation

Planning docs live in `docs/`: `PRD.md` (requirements), `architecture.md` (design and data model),
`rules.md` (engineering rules), `phases.md` (build plan), `design.md` (admin UI), `testing.md`
(test strategy), `api-contracts.md` (admin-ajax and WP-CLI contracts), `memory.md` (work log),
`launch-checklist.md` (release gate).
