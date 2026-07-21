# Security Policy

## Supported versions

Security fixes are provided for the latest 1.0.x release. Older snapshots are not maintained; update
to the current release before reporting an issue.

| Version | Supported |
| --- | --- |
| 1.0.x | Yes |
| < 1.0 | No |

## Reporting a vulnerability

Please report suspected vulnerabilities privately, not through public issues or pull requests.

- Use GitHub's private vulnerability reporting: open the repository's **Security** tab and choose
  **Report a vulnerability**. This keeps the report confidential until a fix is available.

Include, where you can:

- the plugin version, PHP version, and WooCommerce version;
- a description of the issue and its impact;
- steps to reproduce or a proof of concept;
- any relevant log lines (redact the feed auth header value — it is a secret and must never appear
  in a report).

You can expect an acknowledgement within a few business days and a coordinated fix and disclosure
once the issue is confirmed. Please allow a reasonable window to release a fix before any public
disclosure.

## Scope notes

- The only secret the plugin stores is the optional feed auth header value (kept in the
  `wss_settings` option, rendered masked, and excluded from logs, run records, and CLI output).
- All plugin admin surfaces require the `manage_woocommerce` capability and a per-action nonce; there
  are no unauthenticated or shopper-facing endpoints.
- Feed content is treated as untrusted input: values are validated server-side and escaped on output.
