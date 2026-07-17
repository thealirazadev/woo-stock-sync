# Design: woo-stock-sync

This plugin is admin-only: there is no shopper-facing UI. All screens live inside WP Admin under
WooCommerce > Stock Sync and must look native to the WordPress admin, not impose a bespoke visual
system. The plugin ships a small `admin.css` that styles only what core does not provide: status
badges, the diff table, and the progress bar.

## Color and theme

- Inherit the WP admin palette. Body text, links, buttons, and form fields use core admin styles
  (`.button`, `.button-primary`, `.form-table`, `.widefat`); never hard-code the admin accent
  color, since admins can change their color scheme.
- Status badge colors (the one place the plugin introduces color), each paired with dark text for
  contrast and never used as the only signal (the status word is always printed):
  - planned / applied / rolled_back (success family): background `#edfaef`, text `#005c12`.
  - no_change / skipped / cancelled (neutral): background `#f0f0f1`, text `#3c434a`.
  - locked / stalled / previewed (attention): background `#fcf9e8`, text `#674600`.
  - failed / apply_failed / rollback_failed (error): background `#fcf0f1`, text `#8a1f11`.
  All pairs meet WCAG 2.1 AA (>= 4.5:1). Run-level admin notices use core `notice-success`,
  `notice-warning`, `notice-error` classes.
- Progress bar: track `#f0f0f1`, fill uses the current admin accent via
  `background: currentColor` on a text-colored element, with the numeric percentage printed
  beside it so color is never the only indicator.

## Typography and spacing

- Font family, sizes, and line heights inherit from WP admin (13px base). No custom fonts. Screen
  titles use core `h1.wp-heading-inline`; section titles are `h2` at core defaults.
- Numeric columns (current value, new value, counts) use `font-variant-numeric: tabular-nums` so
  diff values align.
- Spacing on the 4/8px system already used by WP admin: 4px inside badges (2px vertical, 8px
  horizontal), 8px between related controls, 16px between form sections, 24px between screen
  regions. Applied via `admin.css` utility rules, not inline styles.
- Border radius: 3px on badges and the progress bar (matches core buttons). No shadows beyond
  core's `.postbox` defaults; flat surfaces otherwise.

## Screens

### Settings (WooCommerce > Stock Sync > Settings)
- One form, three sections in order: Feed source (source type radio, URL, auth header name +
  value, upload field), Schedule (interval select, scheduled mode select), Column mapping (SKU
  select, stock select, regular price select, sale price select, blank-sale-clears checkbox,
  "Load columns" secondary button).
- The auth header value input is `type="password"` with a saved-value placeholder of bullets;
  saving an empty field keeps the stored value, an explicit "clear" checkbox removes it.
- Field-level validation errors render inline under the field in core error styling and are
  announced (see accessibility); the form re-renders with prior input preserved.

### Runs list (WooCommerce > Stock Sync)
- Core `WP_List_Table` styling: columns ID, Date, Trigger, Source, Status (badge), Planned,
  Skipped, Failed, Applied, and a View row action. "Fetch and preview" is the page's primary
  button in the header row.
- Empty state: "No sync runs yet. Configure a feed in Settings, then Fetch and preview." with a
  link to Settings.

### Run detail
- Summary header: status badge, trigger, source, mapping snapshot (collapsed `<details>`), counts
  by status, and the action buttons for the current state: Apply (previewed), Cancel (previewed),
  Resume (stalled), Roll back (latest applied), Release lock (stale lock only).
- Progress region (fetching/diffing/applying/rolling_back): progress bar + "1,250 of 10,000 rows"
  text, updated by polling.
- Diff table (`WP_List_Table`): columns Product (linked to the product editor), SKU, Field,
  Current value, New value, Action/status (badge + reason word), Message. One row per changed
  field for planned rows; one row per feed row for skipped/failed rows. Status filter links above
  the table (All | Planned | No change | Skipped | Failed | Applied), pagination below.
- Empty diff state: "Nothing to change. The store already matches the feed."

### Product editor
- "Lock from stock sync" checkbox in the Inventory panel (and per variation), rendered with
  `woocommerce_wp_checkbox`, with the description "Syncs will never change this product's stock
  or prices."

## Component states

- Buttons: core admin hover/focus/active states. Destructive-ish actions (Apply, Roll back,
  Release lock) use a native `confirm()`-equivalent inline confirmation — the Apply confirmation
  states the run's age ("This preview was created 3 hours ago"), and the Roll back confirmation
  warns that it overwrites any manual edits made since the sync was applied — and are disabled with
  `aria-disabled` plus a spinner (`.spinner.is-active`) while their admin-post request is in
  flight to prevent double submission.
- Disabled: Apply is rendered disabled with an explanatory title when the run is not previewed or
  the lock is held ("Another sync is running").
- Loading: screens render server-side; the only async states are the progress poll (progress bar +
  live counts) and "Load columns" (button shows core spinner, then selects populate).
- Error: run-level problems render as core admin notices after redirect (PRG pattern); row-level
  problems render as badges + messages in the diff table, never as notices.
- Empty: every list and table has the explicit empty-state copy defined above; no bare empty
  tables.

## Accessibility baseline

- Semantic HTML: real `<form>`, `<label>` associated with every input (WooCommerce field helpers
  provide this), `<table>` with `<th scope="col">` for the diff table, `<button>` elements for all
  actions (never clickable divs).
- Keyboard: everything operable in tab order; confirmations are focusable; filter links and
  pagination are plain links. No custom key handling is needed; do not trap focus.
- Visible focus: core admin focus ring preserved everywhere; never `outline: none`.
- Announcements: the progress region is `role="status"` (`aria-live="polite"`) so count updates
  are announced; validation errors are linked to inputs via `aria-describedby`; the settings error
  summary receives focus after a failed save.
- Color contrast: badge pairs above meet AA; status is always conveyed by text as well as color.
- Reduced motion: the progress bar fill transition is disabled under
  `prefers-reduced-motion: reduce`; nothing else animates.
