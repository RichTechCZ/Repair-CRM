# Purpose

GitHub Actions configuration for automated repository quality checks.

# Ownership

Root `AGENTS.md` owns repository-wide quality expectations; this folder owns the CI workflow definition.

# Local Contracts

- `workflows/quality.yml` must lint all tracked PHP sources and run `php tests/run.php` on every push and pull request.
- Keep the workflow dependency-free apart from the PHP runtime installed by the action.

# Work Guidance

- Update the workflow when the supported PHP version or local verification command changes.
- Do not add deployment credentials, production secrets, or external side effects to quality checks.

# Verification

- Run `php tests/run.php` locally before changing the workflow.

# Child DOX Index

- No child contracts.
