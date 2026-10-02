# Codebase Cleanup

This PR is intentionally non-destructive. SQLite is treated as the authoritative runtime store for bracket data; legacy JSON is not rewritten as a side effect of reads.

## Addressed in this PR

- Bracket reads no longer mutate the database by synchronizing the legacy `brackets.rounds` JSON field.
- Bracket schema initialization no longer rewrites legacy JSON for every request.
- Bracket persistence avoids SQLite `INSERT OR REPLACE` semantics that can delete a parent row and cascade into child rows.
- The bracket persistence helper now updates an existing bracket or inserts a new one without replacing the parent record.
- Added an explicit cleanup record documenting the remaining legacy compatibility surface and the intentionally non-destructive scope.

## Follow-up work identified by the audit

- Remove legacy JSON consumers after production verification.
- Replace request-time intramural schema repair with versioned migrations.
- Centralize bracket score-event reconciliation for every bracket mutation.
- Add CSRF protection to all state-changing admin forms.
- Harden image and push-subscription validation.
- Split the oversized configuration/bootstrap files into focused modules.
- Add automated tests for bracket advancement, scoring reconciliation, intramural CRUD, migrations, and leaderboard aggregation.
