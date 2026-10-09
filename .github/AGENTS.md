# Purpose

GitHub Actions configuration for automated repository quality checks.

# Ownership

Root `AGENTS.md` owns repository-wide quality expectations; this folder owns the CI workflow definition.

# Local Contracts

- `workflows/quality.yml` must lint all tracked PHP sources and JavaScript assets and run `php tests/run.php` on every push and pull request.
- The `mysql-integration` job runs migrations twice (fresh + idempotent), `health.php`, and `tests/integration_mysql.php` on a MySQL 8 service with a migration account and a SELECT/INSERT/UPDATE/DELETE-only web account. Credentials there are CI-only throwaway values.
- Keep the workflow dependency-free apart from the PHP runtime installed by the action.

# Work Guidance

- Update the workflow when the supported PHP version or local verification command changes.
- Do not add deployment credentials, production secrets, or external side effects to quality checks.

# Verification

- Run `php tests/run.php` locally before changing the workflow.

# Child DOX Index

- No child contracts.
