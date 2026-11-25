# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The WordPress.org release changelog is kept in `readme.txt`; this file is the fuller developer
history.

## [Unreleased]

### Security

- Remote feeds are fetched with `wp_safe_remote_get()`, so a feed URL can no longer make the server
  request loopback or private-network addresses. Sites with a supplier feed on a private host can
  allow it through core's `http_request_host_is_external` filter.
- The stored auth header value is only sent to the host it was saved for. Changing the feed URL to
  another host requires entering the value again (or clearing it), and the column-listing request
  no longer attaches the stored value to an unsaved URL on a different host.

## [1.0.0] - 2026-07-23

Initial release. Synchronizes WooCommerce stock quantities and prices from a supplier feed by SKU,
with a dry-run diff, batched apply, and rollback.

### Added

- Feed sources: CSV file upload or a remote CSV/JSON URL, with one optional custom auth header. Feed
  files are stored in a protected uploads subdirectory.
- Column mapping keyed by SKU for stock quantity, regular price, and sale price, with server-side
  validation (missing/duplicate SKU, non-numeric values, sale price not below regular).
- Streaming CSV parser (`fgetcsv`) and a JSON decoder with a size cap, both enforcing a row cap.
- Dry-run diff that resolves each row to `planned`, `no_change`, `unknown_sku`, `locked`, or
  `stock_not_managed`, previewing product, field, current value, new value, and action before
  anything is written.
- Batched apply through Action Scheduler with per-row failure isolation, so one bad row never aborts
  the batch; each row records a status, reason, and message.
- Snapshots of prior stock and price values taken once per run and per product, and rollback of the
  most recent applied run from those snapshots.
- Single-run mutex so only one sync fetches, applies, or rolls back at a time, with resume from the
  database cursor and a stale-lock release path.
- Per-product and per-variation "Lock from stock sync" flag that a sync never changes.
- Scheduled recurring fetch (hourly, twice daily, daily) that stops at the preview, with an opt-in
  auto-apply.
- Run history and per-run diff screens (paginated, filterable by row status) backed by custom
  `wss_runs`, `wss_rows`, and `wss_snapshots` tables created through versioned `dbDelta` migrations.
- WP-CLI commands `wp wss fetch`, `dry-run`, `apply`, `rollback`, and `runs`.
- Structured logging through the WooCommerce logger under the `woo-stock-sync` source.
- Full translation coverage of admin strings against the `woo-stock-sync` text domain, and a clean
  uninstall that removes the plugin's tables, options, lock meta, and scheduled actions.

[Unreleased]: https://github.com/thealirazadev/woo-stock-sync/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/thealirazadev/woo-stock-sync/releases/tag/v1.0.0
