=== Woo Stock Sync ===
Contributors: thealirazadev
Tags: woocommerce, inventory, stock, csv, supplier feed
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 8.8
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronize WooCommerce stock quantities and prices from a supplier CSV or JSON feed by SKU, with a dry-run diff, batched apply, and one-click rollback.

== Description ==

Woo Stock Sync keeps your store's stock levels and prices in step with a supplier feed. Point the
plugin at a CSV upload or a remote CSV/JSON URL, map the feed columns to product fields keyed by
SKU, and preview every change on a dry-run diff before anything is written. Applying a sync runs in
background batches through Action Scheduler so it survives shared hosting, each row succeeds or
fails independently with a recorded reason, prior values are snapshotted so the last applied sync
can be rolled back, and a full run history makes every change auditable. Every admin action has a
WP-CLI equivalent.

Product updates go only through the WooCommerce CRUD API, so caches, lookup tables, and stock
status transitions stay correct. The plugin never writes product data with direct SQL.

= Features =

* Dry-run diff before apply: preview product, field, current value, new value, and action per row.
* CSV upload or remote CSV/JSON feed with one optional custom auth header.
* Column mapping keyed by SKU (stock quantity, regular price, sale price).
* Batched apply with per-row failure isolation; one bad row never aborts the batch.
* Rollback of the last applied sync from stored prior values.
* Per-product "Lock from stock sync" flag that a sync never touches.
* Scheduled recurring fetch (hourly, twice daily, daily) with an optional auto-apply.
* Full run history with paginated, filterable per-row results.
* WP-CLI commands: fetch, dry-run, apply, rollback, and runs.

== Installation ==

1. Upload the plugin to `wp-content/plugins/woo-stock-sync` or install it through the Plugins screen.
2. Activate it. WooCommerce must be active first.
3. Go to WooCommerce > Stock Sync > Settings to configure a feed source and column mapping.

== Frequently Asked Questions ==

= Does it create new products from the feed? =

No. Version 1 is update-only. Unknown SKUs are reported, never created.

= Can it undo a sync? =

Yes. The most recent applied sync can be rolled back from the prior values captured at apply time.

== Changelog ==

= 1.0.0 =
* Initial release.
