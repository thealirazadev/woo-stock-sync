# API Contracts: woo-stock-sync

v1 defines no REST routes. The backend surface is admin-post handlers (state changes), admin-ajax
handlers (polling and helpers), and WP-CLI. These contracts are agreed before any code is written;
changing them requires flagging first.

Shared rules:

- Capability: every action below requires `manage_woocommerce`. There are no public surfaces.
- Nonces: every action verifies its own nonce; field name is `<action>_nonce`, nonce action equals
  the action name (e.g. action `wss_apply_run`, field `wss_apply_run_nonce`).
- admin-post responses use the PRG pattern: redirect back to the referring Stock Sync screen with
  `wss_notice=<message-key>&wss_notice_type=success|warning|error` rendered as an admin notice.
- Ajax responses use `wp_send_json_success` / `wp_send_json_error` with the single error shape
  below. HTTP status is 200 with `success: false` (WordPress convention), 400 for malformed
  requests, 403 for nonce/capability failure.

## Single error format

Ajax (body of `wp_send_json_error`):

```json
{ "success": false, "data": { "code": "invalid_run_state", "message": "This run has already been applied." } }
```

CLI: one-line message to STDERR via `WP_CLI::error`, exit code 1. Row-level problems are never
errors at this layer; they are data (statuses + reasons + counts).

Error codes (shared across ajax and CLI): `invalid_nonce`, `forbidden`, `invalid_settings`,
`invalid_run`, `invalid_run_state`, `lock_held`, `no_lock`, `fetch_failed`, `not_latest_applied`,
`nothing_to_rollback`.

## admin-post actions (POST `admin-post.php`)

| action | inputs | success redirect notice |
| --- | --- | --- |
| `wss_save_settings` | `source_type` (upload/url), `feed_url`, `auth_header_name`, `auth_header_value` (blank keeps stored), `auth_header_clear` (0/1), `feed_file` (multipart, csv/json), `schedule` (manual/hourly/twicedaily/daily), `scheduled_mode` (preview_only/auto_apply), `map_sku`, `map_stock`, `map_regular_price`, `map_sale_price`, `blank_clears_sale` (0/1) | settings_saved; on validation failure re-renders with field errors |
| `wss_start_fetch` | none (uses saved settings) | run_started (redirects to the new run detail) |
| `wss_apply_run` | `run_id` (int, must be previewed or stalled) | apply_started |
| `wss_cancel_run` | `run_id` (int, must be previewed) | run_cancelled |
| `wss_rollback_run` | `run_id` (int, must be the latest applied run) | rollback_started |
| `wss_release_lock` | none | lock_released (refused with error notice unless lock is stale) |

Failure cases map to the error codes above rendered as error notices (e.g. `lock_held` on
`wss_start_fetch` while a sync is active).

## admin-ajax actions (POST `admin-ajax.php`)

### `wss_run_progress`

Input: `run_id` (int). Success data:

```json
{
  "success": true,
  "data": {
    "run_id": 42,
    "status": "applying",
    "rows_total": 10000,
    "rows_processed": 1250,
    "rows_planned": 8300,
    "rows_skipped": 1400,
    "rows_failed": 300,
    "rows_applied": 1250,
    "stalled": false
  }
}
```

`rows_processed` counts rows in any terminal status for the current stage. `stalled` is true when
status is active but no row has progressed for 10+ minutes and no action is pending. Clients stop
polling on terminal statuses (`previewed`, `applied`, `rolled_back`, `cancelled`, `failed`).

### `wss_feed_columns`

Input: none (uses saved/submitted source). Reads only the feed header (first CSV line or first
JSON object's keys). Success data:

```json
{ "success": true, "data": { "columns": ["sku", "qty", "price", "sale_price"] } }
```

Failure: `fetch_failed` with a friendly message.

## WP-CLI commands

Registered as `wp wss <subcommand>`. Global behavior: exit 0 on success, 1 on run-level failure;
row-level failures are reported in counts and do not affect the exit code. `--format` defaults to
`table`.

### `wp wss fetch [--source=<url>]`

Creates a run from saved settings (optional URL override), fetches, stages, and diffs
synchronously. Output:

```
Run 42 previewed. Total: 10000, planned: 8300, no change: 1200, skipped: 200, failed: 300.
```

### `wp wss dry-run [--run=<id>] [--status=<status>] [--format=table|json]`

Prints diff rows for the given run (default: latest previewed). Table columns: `product_id`,
`sku`, `field`, `current`, `new`, `status`, `reason`. JSON output is an array of objects with the
same keys. Errors: `invalid_run`, `invalid_run_state`.

### `wp wss apply --run=<id> [--yes]`

Confirms (unless `--yes`), acquires the lock, applies or resumes the run in the same batch loop as
admin, printing a progress line per batch. Final output:

```
Run 42 applied. Applied: 8290, skipped: 1400, failed: 310 (unknown_sku: 180, invalid_number: 90, locked: 30, wc_error: 10).
```

Errors: `invalid_run`, `invalid_run_state`, `lock_held`.

### `wp wss rollback [--yes]`

Rolls back the latest applied run. Output mirrors apply with rolled_back / rollback_failed counts.
Errors: `nothing_to_rollback`, `not_latest_applied`, `lock_held`.

### `wp wss runs [--format=table|json]`

Lists run history, newest first. Table columns: `id`, `date`, `trigger`, `source`, `status`,
`planned`, `skipped`, `failed`, `applied`. Empty history prints an empty table, exit 0.
