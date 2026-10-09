# Test Documentation

## Purpose

Provides dependency-free regression tests for security and financial rules that can run without a database or external services.

## Ownership

Root AGENTS.md -> tests/AGENTS.md

## Local Contracts

- `run.php` is the single test runner and must exit non-zero on the first failed assertion.
- Tests cover pure helpers and source-level guardrails for upload policy, admin/session separation, locked status/inventory workflows, Issued Self Pickup vs delivery-expense rules, Status date sync into status history, phone QR trigger without a hover tooltip, migration failure handling, DOM-safe invoice rows, Telegram security, Telegram Bot router and scoping, financial formulas and print totals, empty A4 invoice `Vystavil` issuer, encrypted PINs, trusted-template CSP nonces/no inline handlers/SRI, indexed search, batched transition-date reporting, signed credit notes, disabled web updates, Issued `shipping_date` stamping, numeric `changed_by` in status history, the closed-order edit lock, in-memory XML/CSV accounting exports (escaping, formula neutralisation), `SCRIPT_NAME` page guards, Select2 selection escaping, Telegram numeric-id binding/no runtime DDL/search and report contracts, numbered-only migrations, and POST+CSRF logout.
- Tests must not read `.env`, connect to the database, or call external APIs. In-memory SQLite fixtures are allowed for code paths that use portable SQL; restore `$_SESSION` and `$GLOBALS['pdo']` afterwards.
- `run.php` refuses non-CLI execution.
- `integration_mysql.php` runs against a migrated disposable MySQL/MariaDB database (DB_NAME must end in `_test`, `_ci` or `_audit`; `CRM_INTEGRATION_DB=1`) with the least-privilege web account. It covers the binding finance formulas, cost snapshots, stock transitions, shipping_date stamping/clearing, numeric status-history actors, invoice numbering and VAT policy, auto-invoice behaviour, technician scoping and dashboard counts.

## Work Guidance

- Add regression coverage whenever a security, authorization, or financial rule changes.
- Prefer direct behavior assertions; use source-level checks only when an endpoint cannot be bootstrapped without infrastructure.

## Verification

- Run `php tests/run.php`.
- With a disposable database: `php run_migrations.php` (twice) and `CRM_INTEGRATION_DB=1 php tests/integration_mysql.php`.

## Child DOX Index

None.
