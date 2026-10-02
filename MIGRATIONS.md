# Database migrations

GroupStatistics now treats SQLite as the runtime source of truth.

## Runtime behavior

`config.php` loads `database-migrations.php`, which executes every numbered file in `migrations/` exactly once and records applied versions in `schema_migrations`.

Migrations are transactional. A failed migration is rolled back and is retried on the next startup.

## Rules

1. Never repair tables by dropping/recreating them during a normal web request.
2. Add a new numbered migration for every schema change.
3. Make migrations safe to run once and record them in `schema_migrations`.
4. Keep legacy imports one-time and archive their source files instead of reading them at runtime.
5. Back up `/data/squadron-tracker.db` before applying destructive production migrations.

## Current migrations

- `001_core_schema.php` — canonical application tables and indexes.
- `002_notifications.php` — SQLite notification subscriptions.
- `003_intramural_cleanup.php` — adds/backfills immutable `sport_id` for intramural games.
- `004_legacy_import.php` — one-time import/archive of legacy squadron, score, and bracket JSON.
