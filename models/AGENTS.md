# Models Documentation

## Purpose
Contains class definitions and object-oriented models for business logic (invoices, order status, AI).

## Ownership
Root AGENTS.md -> models/AGENTS.md

## Local Contracts
- `InvoiceManager.php` / `InvoiceAutomation.php` / `MyInvoiceApiClient.php` — accounting and MyInvoice sync.
- `OrderStatusService.php` — shared order status transition rules: terminal lock, Issued validation, inventory consume/return, auto-invoice, admin TG notify, technician reassignment TG.
- `nvidia_ai.php` — optional AI helpers.

## Work Guidance
- New multi-endpoint domain rules belong here (not copied across `api/*.php`).
- Keep functions/classes free of HTTP output; endpoints call them and format JSON.

## Verification
- `php -l models/*.php` after changes.

## Child DOX Index
None.
