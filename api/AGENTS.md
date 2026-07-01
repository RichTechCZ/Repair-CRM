# API Documentation

## Purpose
Manages API endpoints and integration scripts (PHP) for the CRM.

## Ownership
Root AGENTS.md -> api/AGENTS.md

## Local Contracts
- Endpoint logic is contained in independent PHP files.
- Orders in terminal statuses (`Issued`, `Issued Without Repair`, `Repair Cancelled`) may be moved to another status only by users with `admin_access`.
- Inventory is consumed only for actually-repaired/handed-over statuses (`Ready`, `Issued`). `Issued Without Repair` and `Repair Cancelled` do NOT write off parts — moving to them returns previously consumed parts to stock.
- Parts added/edited/removed while an order is in a consuming status (`Ready`, `Issued`) adjust stock immediately; in other statuses stock is adjusted by the status transition only.
- Deleting an order is admin-only (`admin_access`), blocked when active (non-cancelled) invoices exist, and returns consumed parts to stock before removal.
- Leaving a consuming status reverts auto-created invoices (cancelled) so a job back in progress has no stale issued invoice.
- Reassigning an order's technician sends a Telegram notification to the newly assigned technician (newly assigned orders).

## Work Guidance

## Verification

## Child DOX Index
None.
