# Write Endpoint Audit

Date: 2026-03-29

Scope:
- Static review of mutating endpoints in `api/`
- Safe runtime verification of read-only pages and APIs only
- No data-changing requests were executed for this audit

Patched in this pass:
- `api/add_order_item.php`: now requires `POST` and valid CSRF token
- `api/parse_mobilnidily.php`: now requires `POST` and valid CSRF token
- `accounting_actions.php` export actions: now require `POST` and valid CSRF token

Current status:
- Most mutating endpoints check authentication and CSRF correctly
- Many mutating endpoints still do not explicitly reject non-`POST` methods, even though they expect form/AJAX `POST`
- Read-only APIs verified during audit: `get_customer_orders`, `get_order_details`, `get_orders_for_report`, `get_invoice_data`, `get_invoice_details`, `search_customers`

Endpoints reviewed as mutating:
- `add_customer.php`
- `add_inventory.php`
- `add_order_item.php`
- `add_order.php`
- `backup_db.php`
- `create_express_invoice.php`
- `create_invoice.php`
- `delete_customer.php`
- `delete_inventory.php`
- `delete_media.php`
- `delete_order_item.php`
- `delete_order.php`
- `parse_mobilnidily.php`
- `run_update.php`
- `update_attachment_date.php`
- `update_invoice.php`
- `update_order_dates.php`
- `update_order_full.php`
- `update_order_item.php`
- `update_order_status.php`
- `update_shipping.php`
- `upload_media.php`

Remaining hardening recommendations:
- Add explicit `POST`-only checks to the remaining mutating endpoints that already rely on CSRF
- Decide whether `api/check_updates.php` is acceptable as `GET` with cache write, or move cache persistence behind `POST`
- Consider centralizing API method/auth/CSRF guards to reduce drift between endpoints
