# Launch Checklist: woo-stock-sync

Check every item before shipping a release.

## General

- [ ] Version bumped everywhere: plugin header, `WSS_VERSION`, `readme.txt` stable tag match.
- [ ] Debug off in production: `WP_DEBUG` and `WP_DEBUG_DISPLAY` false; `wss_log` writes only
      through WC_Logger; no raw output leaks to screens.
- [ ] Error tracking connected: WooCommerce > Status > Logs shows the `woo-stock-sync` source;
      site-level error monitoring in place.
- [ ] Loading states everywhere: progress bar during fetch/diff/apply/rollback; spinners on
      Apply/Roll back/Load columns; buttons disabled while requests are in flight.
- [ ] Failure pages sane: a run-level failure shows a friendly notice, sets the run failed, and
      releases the lock; no admin screen fatals when a run, product, or feed file is missing.
- [ ] Mobile/small screens checked: settings form, runs list, and diff table usable at narrow
      admin widths.

## Project-specific

- [ ] Migrations verified: fresh install and upgrade from the previous release both land on the
      current `wss_db_version` with no dbDelta warnings.
- [ ] Batch safety verified on a large feed (10,000+ rows) on a hosting-like environment: apply
      completes over many Action Scheduler batches, a killed worker resumes without double writes.
- [ ] Overlap protection verified: concurrent apply attempts refused; stale lock releasable;
      scheduled fetch skips while locked.
- [ ] Rollback verified against a real applied run, including a deleted product
      (rollback_failed/product_missing) and manual-edit overwrite warning shown before rollback.
- [ ] Partial-failure isolation verified: mixed fixture feed applies valid rows only; summary
      counts match per-row statuses exactly.
- [ ] Security pass: `manage_woocommerce` + per-action nonces on every handler; auth header value
      masked and absent from logs, run records, and CLI output; uploads dir protected; all
      feed-derived output escaped; all plugin SQL prepared.
- [ ] Scheduled sync verified: recurring action fires at the configured interval on a real site;
      auto_apply honored; preview_only stops at previewed.
- [ ] WP-CLI verified: fetch, dry-run, apply, rollback, runs all exit with documented codes and
      match `docs/api-contracts.md`.
- [ ] Uninstall clean: tables, options, `_wss_locked` meta, uploaded feeds, and scheduled actions
      removed.
- [ ] Compatibility: tested against declared WordPress, WooCommerce, and PHP minimums; headers
      accurate; PHPCS clean; PHPUnit green.
- [ ] i18n complete: `languages/woo-stock-sync.pot` regenerated; all strings translatable.
