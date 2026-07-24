---
name: Bug report
about: Report a problem with a sync, diff, apply, rollback, or the admin screens
title: ''
labels: bug
assignees: ''
---

<!--
Before filing: please update to the latest 1.0.x release and confirm the bug still happens.
Do NOT paste your feed auth header value anywhere in this report; it is a secret.
For a suspected security issue, use the Security policy instead of a public issue.
-->

## Describe the bug

A clear and concise description of what went wrong.

## To reproduce

Steps to reproduce the behavior:

1. Feed source (upload or remote URL) and format (CSV or JSON):
2. Column mapping used (SKU, stock, regular price, sale price):
3. Action taken (Fetch and preview, Apply, Roll back, scheduled run, WP-CLI command):
4. What happened:

## Expected behavior

What you expected to happen instead.

## A run and its rows

If the problem is about a specific sync run, please include:

- The run status badge (e.g. failed, applied, stalled).
- The row counts (planned / no change / skipped / failed / applied).
- Any row `reason` codes shown in the diff table (e.g. `unknown_sku`, `invalid_number`, `locked`).
- Relevant lines from **WooCommerce > Status > Logs** under the `woo-stock-sync` source
  (redact the feed auth header value).

## Environment

- Plugin version:
- WordPress version:
- WooCommerce version:
- PHP version:
- Hosting / server (if relevant, e.g. shared host, object cache, Action Scheduler backlog):

## Additional context

Screenshots or anything else that would help. If it is a parsing issue, a small, sanitized
excerpt of the feed (a few rows, secrets removed) is very helpful.
