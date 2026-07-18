# API Documentation

## Purpose
Manages API endpoints and integration scripts (PHP) for the CRM.

## Ownership
Root AGENTS.md -> api/AGENTS.md

## Local Contracts
- Endpoint logic is contained in independent PHP files.
- **Bootstrap:** mutating endpoints start with `require_once __DIR__ . '/../includes/api_bootstrap.php'` + `api_bootstrap([...])` (auth, POST, CSRF, optional permission/role, rate limit, JSON).
- Orders in terminal statuses (`Issued`, `Issued Without Repair`, `Repair Cancelled`) may be moved to another status only by users with `admin_access`.
- Inventory is consumed only for actually-repaired/handed-over statuses (`Ready`, `Issued`). `Issued Without Repair` and `Repair Cancelled` do NOT write off parts — moving to them returns previously consumed parts to stock.
- Parts added/edited/removed while an order is in a consuming status (`Ready`, `Issued`) adjust stock immediately; in other statuses stock is adjusted by the status transition only.
- Inventory mutations (`add_inventory`, `delete_inventory`, and page `edit_inventory`) require `admin_access`; write endpoints must be POST + CSRF.
- Deleting an order is admin-only (`admin_access`), blocked when active (non-cancelled) invoices exist, and returns consumed parts to stock before removal.
- Leaving a consuming status reverts auto-created invoices (cancelled) so a job back in progress has no stale issued invoice.
- Reassigning an order's technician sends a Telegram notification to the newly assigned technician (newly assigned orders).
- Technician-scoped users (`isTechnicianScoped()`): `get_customer_orders` returns only their `technician_id` orders; `search_customers` only customers with at least one of their orders.
- Client-facing API errors must use `publicExceptionMessage($e)` / `api_json_exit` — never raw PDO/stack/path messages.
- Status transitions share `models/OrderStatusService.php` (`update_order_status.php`, `update_order_full.php`).

## Work Guidance
- Prefer `api_bootstrap()` for all new write endpoints; set `rate` action name uniquely.
- Form-redirect endpoints (e.g. `add_order`, `parse_catalog`) use `'json' => false` and optional custom `fail` callback.
- Log system failures with `error_log`; return localized/safe messages to the browser.

## Verification
- `php -l` on changed API files after edits.

## Child DOX Index
None.
