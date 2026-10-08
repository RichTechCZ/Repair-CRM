# Test Documentation

## Purpose

Provides dependency-free regression tests for security and financial rules that can run without a database or external services.

## Ownership

Root AGENTS.md -> tests/AGENTS.md

## Local Contracts

- `run.php` is the single test runner and must exit non-zero on the first failed assertion.
- Tests cover pure helpers and source-level guardrails for upload policy, admin/session separation, locked status/inventory workflows, Issued Self Pickup vs delivery-expense rules, Status date sync into status history, phone QR trigger without a hover tooltip, migration failure handling, DOM-safe invoice rows, Telegram security, Telegram Bot router and scoping, financial formulas and print totals, empty A4 invoice `Vystavil` issuer, encrypted PINs, trusted-template CSP nonces/no inline handlers/SRI, indexed search, batched transition-date reporting, signed credit notes, and disabled web updates.
- Tests must not read `.env`, connect to the database, or call external APIs.

## Work Guidance

- Add regression coverage whenever a security, authorization, or financial rule changes.
- Prefer direct behavior assertions; use source-level checks only when an endpoint cannot be bootstrapped without infrastructure.

## Verification

- Run `php tests/run.php`.

## Child DOX Index

None.
