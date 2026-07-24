<!--
Thanks for contributing to Woo Stock Sync. Please read CONTRIBUTING.md first.
Keep each pull request to one discrete change in a working state.
-->

## Summary

What does this change do, and why? Link the issue it addresses (e.g. `Closes #123`).

## Type of change

- [ ] Bug fix
- [ ] New feature
- [ ] Refactor (no behavior change)
- [ ] Documentation
- [ ] Tests or tooling

## How it was tested

Describe how you verified the change (manual steps, and which suite mode you ran).

## Checklist

- [ ] `composer run lint` (phpcs, WordPress Coding Standards) is clean.
- [ ] `composer run test` (phpunit) passes; the integration suite was run if the change touches the
      fetch/diff/apply/rollback pipeline.
- [ ] New or changed behavior is covered by tests (unit for pure parsing/validation, integration for
      anything writing products through WooCommerce CRUD).
- [ ] Product reads/writes go through WooCommerce CRUD; plugin tables use `$wpdb->prepare` or the
      format-array methods only.
- [ ] Any schema change is a new numbered `dbDelta` migration step in `WSS_Install` (existing steps
      are not edited).
- [ ] User-facing strings are wrapped in the `woo-stock-sync` text domain and output is escaped; the
      `.pot` was regenerated if strings were added or changed.
- [ ] Commits follow Conventional Commits, one discrete change each, with no emoji or authorship
      mentions.
- [ ] No new dependency was added without prior agreement; `composer.lock` is committed if deps
      changed.

## Notes for reviewers

Anything you want the reviewer to look at closely, or follow-ups you deliberately left out of scope.
