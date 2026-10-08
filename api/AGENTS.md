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
- `test_tech_tg.php` is an admin-only diagnostic transport check (`admin_access`); it is not a technician notification workflow.
- Technician-scoped users (`isTechnicianScoped()`): `get_customer_orders` returns only their `technician_id` orders; `search_customers` only customers with at least one of their orders.
- Client-facing API errors must use `publicExceptionMessage($e)` / `api_json_exit` — never raw PDO/stack/path messages.
- Status transitions share `models/OrderStatusService.php` (`update_order_status.php`, `update_order_full.php`, page `edit_order.php`, and external `sync_from_site.php`). Issued + `Self Pickup` or order type `Warranty` / «Рекламация» must not demand a positive final cost.
- `update_order_dates.php` updates `orders.created_at` / `updated_at` and the current `order_status_log.changed_at` (plus Issued `shipping_date`) under `SELECT ... FOR UPDATE`.
- Mutable order/item/inventory reads happen inside a transaction with `SELECT ... FOR UPDATE`; quantities are positive integers and financial values are finite and non-negative.
- `add_order.php` / `upload_media.php` store attachments only through `crmStoreOrderUploads()`.
- `add_order.php` encrypts non-empty device PINs and restricts scoped technicians to shared customers or a valid newly-created-customer grant.
- `run_update.php` is a deliberate `410 Gone` tombstone. Web code installation must not be reintroduced.
- Backup creation streams a repeatable-read snapshot to a mode-0600 file under `app/backup_db/` via `crmBackupDirectory()` (override with `CRM_BACKUP_DIR`); creation and download require `admin_access`. The directory is web-denied by root `.htaccess` and a local guard file.
- Invoice mutations are POST + CSRF, invoice/order ownership is checked under lock, and linked order cost updates commit atomically with the invoice.
- `public_order_status.php` is unauthenticated JSON for the public status UI: rate-limited, accepts only the 8-char `public_status_token`, and returns non-sensitive fields only (no PIN, phone, notes, costs, or customer name). The customer-facing page is root `status.php` on app.servis.expert.
- `copy_order.php` / `get_order.php` are authenticated GET helpers for the new-order form. They must not return device PIN (encrypted or decrypted). Prefer `api_bootstrap()`.
- `download_backup.php` requires `admin_access` and a valid CSRF token (query/body) even for GET downloads.
- Inventory write validation: quantities are non-negative integers; money values finite and non-negative.

## Work Guidance
- Prefer `api_bootstrap()` for all new write endpoints; set `rate` action name uniquely.
- Form-redirect endpoints (e.g. `add_order`, `parse_catalog`) use `'json' => false` and optional custom `fail` callback. `add_order` starts an output buffer and redirects anonymous requests immediately after `config.php`, then applies `api_bootstrap()` to the authenticated POST; this avoids host-specific empty `500` responses without bypassing CSRF or rate limiting. It redirects with `created_order_id` on success; failures use a safe session flash rather than raw `die()` output.
- `add_customer` supports both browser form redirects and explicit JSON responses (`response_format=json` or an `Accept: application/json` request); clients must state the intended response format instead of relying only on `X-Requested-With`.
- JSON paths in `add_customer.php` must use `api_json_exit` (never bare `echo json_encode` + trailing `?>`) so the inline new-order UI does not show a false network error.
- Log system failures with `error_log`; return localized/safe messages to the browser.
- Telegram delivery happens after order commit and is best-effort: catch/log notification failures so a created order still redirects successfully.
- `add_customer.php` grants a scoped technician 15 minutes to create the first order for that exact new customer; the grant is consumed only after a successful order commit. When `customers.phone_search` is missing (pre-migration schema), insert without that column rather than failing closed.
- Invoice previews use `final_cost` (falling back to `estimated_cost`) as the customer charge and never add order-item prices to the invoice total.
- `add_order` has a shutdown fallback for otherwise uncatchable runtime failures. It writes only timestamp, execution stage, error type, and message to the access-denied `temp/add_order_runtime.log`; never write submitted order or customer data there.

## Verification
- `php -l` on changed API files after edits.

## Child DOX Index
None.
