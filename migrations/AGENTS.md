# Migrations Documentation

## Purpose
Database schema definitions and SQL migration scripts.

## Ownership
Root AGENTS.md -> migrations/AGENTS.md

## Local Contracts
- `includes/migration_runner.php` is the executable migration contract. Known legacy migrations have idempotent handlers; SQL files remain ordered, durable schema records.
- `001_bootstrap.sql` establishes the base schema; `002_*` legacy filenames are preserved for compatibility; `003_production_hardening.sql` supplies account-scoped login throttling, `technicians.engineer_rate`, `invoices.updated_at`, and `system_errors`.
- `004_search_sensitive_reporting.sql` adds indexed FULLTEXT/prefix search, report indexes, stable legacy status timestamps, and application-key encryption of existing device PINs.
- `005_public_status_token.sql` adds unique `orders.public_status_token` (CHAR(8)) for customer QR status links (`app.servis.expert/status.php?id=XXXXXXXX`); sequential order IDs must not appear in public status URLs.
- `006_telegram_bot_state.sql` adds `telegram_bot_states` table for Telegram bot dialog FSM states and adds optional `users.telegram_id`.
- A migration is recorded only after its handler succeeds. Any error stops the CLI deployment run.
- Production migrations are CLI-only and use `DB_MIGRATION_USER`; the web runtime account must not have DDL privileges.

## Work Guidance
- Add new migrations with the next unique numeric prefix and an idempotent handler when raw SQL cannot safely tolerate partially upgraded installations.
- Never edit an already-deployed migration without also preserving compatibility in the shared runner.

## Verification
- Run `php -l includes/migration_runner.php run_migrations.php`.
- Run `php run_migrations.php` against a disposable database when database infrastructure is available; verify both fresh install and second-run idempotency.

## Child DOX Index
None.
